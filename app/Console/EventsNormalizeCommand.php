<?php

declare(strict_types=1);

namespace App\Console;

use App\Account\AccountStatus;
use App\Core\Encryptor;
use App\Ingest\EventProcessor;
use App\Ingest\EventStatus;
use App\Ingest\EventStore;
use App\Meta\EventNormalizer;
use Nette\Database\Explorer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


/**
 * Fills the metadata of stored events (type, external ID, time) from EventNormalizer: events
 * stored before the normalizer existed, or with --all every event after a normalizer change.
 * Processed events of active accounts are also projected into comments and conversations (the
 * projection is idempotent). The status stays as it is; the command never triggers processing,
 * an AI decision or any action.
 */
#[AsCommand(name: 'events:normalize', description: 'Fills type, external ID and time of stored events and projects comments and messages')]
final class EventsNormalizeCommand extends Command
{
	private const BatchSize = 500;


	public function __construct(
		private readonly Explorer $database,
		private readonly Encryptor $encryptor,
		private readonly EventNormalizer $normalizer,
		private readonly EventProcessor $processor,
	) {
		parent::__construct();
	}


	protected function configure(): void
	{
		$this->addOption('all', null, InputOption::VALUE_NONE, 'Normalize all events, not only those without a type');
	}


	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$filter = $input->getOption('all') ? [] : ['e.event_type' => null];
		$counts = [];
		$lastId = 0;
		do {
			$rows = $this->database->query(
				'SELECT e.id, e.account_id, e.status, e.payload_encrypted, e.received_at, a.status AS account_status
				FROM social_events e
				LEFT JOIN social_accounts a ON a.id = e.account_id
				WHERE e.id > ? AND ?and
				ORDER BY e.id
				LIMIT ?',
				$lastId,
				$filter,
				self::BatchSize,
			)->fetchAll();

			foreach ($rows as $row) {
				$lastId = $row->id;
				$normalized = $this->normalizer->normalize(
					$this->encryptor->decrypt($row->payload_encrypted, EventStore::PayloadContext),
				);
				$project = $row->account_status === AccountStatus::Active->value && $row->status !== EventStatus::Received->value;
				$this->database->transaction(function () use ($row, $normalized, $project): void {
					$this->database->query('UPDATE social_events SET ? WHERE id = ?', $normalized->columns(), $row->id);
					if ($project) {
						$this->processor->project((int) $row->account_id, $normalized, $row->received_at);
					}
				});
				$counts[$normalized->type->value] = ($counts[$normalized->type->value] ?? 0) + 1;
			}
		} while (count($rows) === self::BatchSize);

		ksort($counts);
		foreach ($counts as $type => $count) {
			$output->writeln("$type: $count");
		}

		$output->writeln(sprintf('%d events normalized', array_sum($counts)));

		return self::SUCCESS;
	}
}
