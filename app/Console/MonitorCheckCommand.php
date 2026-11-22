<?php

declare(strict_types=1);

namespace App\Console;

use App\Monitoring\Alarms;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


/**
 * Prints the current alarms for an external monitor (cron, uptime check); exits with 1 when there
 * is any, so the monitor can notify without the application choosing a channel.
 */
#[AsCommand(name: 'monitor:check', description: 'Prints current alarms; exit code 1 when there is any')]
final class MonitorCheckCommand extends Command
{
	public function __construct(
		private readonly Alarms $alarms,
	) {
		parent::__construct();
	}


	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$alarms = $this->alarms->current();
		if ($alarms === []) {
			$output->writeln('OK: no alarms');

			return self::SUCCESS;
		}

		foreach ($alarms as $alarm) {
			$output->writeln('ALARM: ' . $alarm->describe());
		}

		return self::FAILURE;
	}
}
