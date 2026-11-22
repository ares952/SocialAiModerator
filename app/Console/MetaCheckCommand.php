<?php

declare(strict_types=1);

namespace App\Console;

use App\Account\TokenCheck;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


/**
 * Checks the stored access tokens against Meta now (only reads) and prints validity, expiry and
 * missing permissions per account; exit code 1 when any token is not usable for all features.
 */
#[AsCommand(name: 'meta:check', description: 'Checks the stored Meta access tokens (read only)')]
final class MetaCheckCommand extends Command
{
	public function __construct(
		private readonly TokenCheck $check,
	) {
		parent::__construct();
	}


	protected function configure(): void
	{
		$this->addOption('account', null, InputOption::VALUE_REQUIRED, 'Only this account ID');
	}


	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$account = $input->getOption('account');
		$results = $account === null ? $this->check->all() : [
			$this->check->account((int) $account),
		];
		if ($results === []) {
			$output->writeln('No account has a stored token.');

			return self::SUCCESS;
		}

		$ok = true;
		foreach ($results as $result) {
			$ok = $ok && $result->ok();
			$output->writeln(sprintf(
				'%s #%d %s (%s): %s',
				$result->ok() ? 'OK' : 'PROBLEM',
				$result->accountId,
				$result->accountName,
				$result->platform->value,
				$result->status === null ? 'check failed' : $result->status->value,
			));
			if ($result->problem !== null) {
				$output->writeln("  $result->problem");
			}
			if ($result->info !== null && $result->info->valid) {
				$output->writeln('  type: ' . ($result->info->type ?? '?')
					. ', expires: ' . ($result->info->expiresAt?->format('Y-m-d H:i') ?? 'never')
					. ', data access until: ' . ($result->info->dataAccessExpiresAt?->format('Y-m-d H:i') ?? 'not limited'));
				$output->writeln('  scopes: ' . implode(', ', $result->info->scopes));
				foreach ($result->info->targets as $scope => $ids) {
					$output->writeln("  $scope limited to: " . implode(', ', $ids));
				}
			}
			if ($result->missingScopes !== []) {
				$output->writeln('  missing scopes: ' . implode(', ', $result->missingScopes));
			}
		}

		return $ok ? self::SUCCESS : self::FAILURE;
	}
}
