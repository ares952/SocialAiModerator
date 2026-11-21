<?php

declare(strict_types=1);

namespace App\Console;

use App\Retention\Retention;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


/**
 * Applies the retention periods now; the worker does the same every hour.
 */
#[AsCommand(name: 'retention:run', description: 'Deletes stored data older than its retention period')]
final class RetentionRunCommand extends Command
{
	public function __construct(
		private readonly Retention $retention,
	) {
		parent::__construct();
	}


	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$deleted = $this->retention->purge();
		if ($deleted === []) {
			$output->writeln('No retention period is set; nothing deleted.');
		}

		foreach ($deleted as $data => $count) {
			$output->writeln("$data: $count deleted");
		}

		return self::SUCCESS;
	}
}
