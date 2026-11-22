<?php

declare(strict_types=1);

namespace App\Console;

use App\Account\Credentials;
use App\Comment\Comments;
use App\Conversation\Conversations;
use App\Core\DecryptionFailed;
use App\Core\Encryptor;
use App\Evaluation\EvaluationSamples;
use App\Ingest\EventStore;
use App\Moderation\Decisions;
use App\Outbox\Actions;
use Nette\Database\Explorer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


/**
 * Re-encrypts all data encrypted at rest with the current key (first in APP_ENCRYPTION_KEY), so
 * an old key can be removed afterwards (docs/meta-webhooks.md). With --dry-run it only checks
 * that everything decrypts, e.g. after restoring a backup.
 */
#[AsCommand(name: 'encryption:rotate', description: 'Re-encrypts data at rest with the current APP_ENCRYPTION_KEY')]
final class EncryptionRotateCommand extends Command
{
	/** table => [column, encryption context] */
	private const Columns = [
		'account_credentials' => ['encrypted_token', Credentials::TokenContext],
		'social_events' => ['payload_encrypted', EventStore::PayloadContext],
		'evaluation_samples' => ['text_encrypted', EvaluationSamples::TextContext],
		'moderation_decisions' => ['reason_encrypted', Decisions::ReasonContext],
		'social_comments' => ['text_encrypted', Comments::TextContext],
		'social_messages' => ['text_encrypted', Conversations::TextContext],
		'moderation_actions' => ['text_encrypted', Actions::TextContext],
	];

	private const BatchSize = 500;


	public function __construct(
		private readonly Explorer $database,
		private readonly Encryptor $encryptor,
	) {
		parent::__construct();
	}


	protected function configure(): void
	{
		$this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only check that everything decrypts; change nothing');
	}


	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$dryRun = (bool) $input->getOption('dry-run');
		$output->writeln("current key {$this->encryptor->currentKeyId()}" . ($dryRun ? ' (dry run)' : ''));

		$failed = 0;
		foreach (self::Columns as $table => [$column, $context]) {
			$counts = $this->rotate($table, $column, $context, $dryRun);
			$failed += $counts['failed'];
			$output->writeln(sprintf(
				'%s.%s: %d current, %d %s, %d failed',
				$table,
				$column,
				$counts['current'],
				$counts['outdated'],
				$dryRun ? 'to re-encrypt' : 're-encrypted',
				$counts['failed'],
			));
		}

		if ($failed > 0) {
			$output->writeln("<error>$failed values cannot be decrypted with the configured keys.</error>");

			return self::FAILURE;
		}

		return self::SUCCESS;
	}


	/**
	 * @return array{current: int, outdated: int, failed: int}
	 */
	private function rotate(string $table, string $column, string $context, bool $dryRun): array
	{
		$counts = [
			'current' => 0,
			'outdated' => 0,
			'failed' => 0,
		];
		$lastId = 0;
		do {
			$rows = $this->database->query(
				'SELECT id, ?name AS value FROM ?name WHERE id > ? AND ?name IS NOT NULL ORDER BY id LIMIT ?',
				$column,
				$table,
				$lastId,
				$column,
				self::BatchSize,
			)->fetchAll();

			foreach ($rows as $row) {
				$lastId = $row->id;
				try {
					$plaintext = $this->encryptor->decrypt($row->value, $context);
				} catch (DecryptionFailed) {
					$counts['failed']++;
					continue;
				}

				if (!$this->encryptor->isOutdated($row->value)) {
					$counts['current']++;
					continue;
				}

				$counts['outdated']++;
				if (!$dryRun) {
					// Only if unchanged meanwhile; a concurrent write is already current.
					$this->database->query(
						'UPDATE ?name SET ?name = ? WHERE id = ? AND ?name = ?',
						$table,
						$column,
						$this->encryptor->encrypt($plaintext, $context),
						$row->id,
						$column,
						$row->value,
					);
				}
			}
		} while (count($rows) === self::BatchSize);

		return $counts;
	}
}
