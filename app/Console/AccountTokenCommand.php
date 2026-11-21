<?php

declare(strict_types=1);

namespace App\Console;

use App\Account\Credentials;
use App\Account\InvalidCredential;
use App\Account\TokenType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;


/**
 * Stores or replaces the Meta access token of an account (docs/meta-webhooks.md). The token is
 * asked for without echo, never accepted as an argument, so it does not end up in the shell
 * history or the process list. Takes effect immediately, no restart needed.
 */
#[AsCommand(name: 'account:token', description: 'Stores or replaces the Meta access token of an account; asks for the token')]
final class AccountTokenCommand extends Command
{
	public function __construct(
		private readonly Credentials $credentials,
	) {
		parent::__construct();
	}


	protected function configure(): void
	{
		$types = implode(', ', array_column(TokenType::cases(), 'value'));
		$this->addArgument('account', InputArgument::REQUIRED, 'Account ID in the administration (Accounts)');
		$this->addOption('type', null, InputOption::VALUE_REQUIRED, "Token type: $types");
		$this->addOption('scopes', null, InputOption::VALUE_REQUIRED, 'Granted permissions, comma-separated');
		$this->addOption('expires', null, InputOption::VALUE_REQUIRED, 'Expiry in UTC (YYYY-MM-DD or "YYYY-MM-DD HH:MM") or "never"');
		$this->addOption('data-access-expires', null, InputOption::VALUE_REQUIRED, 'Data access expiry in UTC (YYYY-MM-DD or "YYYY-MM-DD HH:MM")');
	}


	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$account = (string) $input->getArgument('account');
		$type = TokenType::tryFrom((string) $input->getOption('type'));
		$scopes = array_values(array_filter(array_map(trim(...), explode(',', (string) $input->getOption('scopes')))));
		$expires = (string) $input->getOption('expires');
		$dataAccess = $input->getOption('data-access-expires');

		try {
			if (!preg_match('~^[1-9]\d{0,9}$~D', $account)) {
				throw new InvalidCredential('The account must be a numeric ID.');
			}
			if ($type === null) {
				throw new InvalidCredential('Unknown or missing --type.');
			}
			if ($expires === '') {
				throw new InvalidCredential('--expires is required (a date or "never").');
			}

			$expiresAt = $expires === 'never' ? null : self::date($expires, '--expires');
			$dataAccessExpiresAt = $dataAccess === null ? null : self::date((string) $dataAccess, '--data-access-expires');

			$helper = $this->getHelper('question');
			assert($helper instanceof QuestionHelper);
			$question = new Question('Access token: ');
			$question->setHidden(true);
			$token = trim((string) $helper->ask($input, $output, $question));

			$this->credentials->replace((int) $account, $token, $type, $scopes, $expiresAt, $dataAccessExpiresAt, operatorId: null);
		} catch (InvalidCredential $e) {
			$output->writeln("<error>{$e->getMessage()}</error>");

			return self::FAILURE;
		}

		$stored = $this->credentials->find((int) $account) ?? throw new \LogicException('The token was not stored.');
		$output->writeln("token of account $account stored ($type->value, key $stored->key_id, status $stored->status)");

		return self::SUCCESS;
	}


	/**
	 * @throws InvalidCredential
	 */
	private static function date(string $value, string $option): \DateTimeImmutable
	{
		$utc = new \DateTimeZone('UTC');
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, $utc)
			?: \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $utc);
		if ($date === false || \DateTimeImmutable::getLastErrors() !== false) {
			throw new InvalidCredential("$option must be YYYY-MM-DD or \"YYYY-MM-DD HH:MM\".");
		}

		return $date;
	}
}
