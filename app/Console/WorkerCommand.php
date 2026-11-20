<?php

declare(strict_types=1);

namespace App\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


/**
 * Long-running background worker (the `worker` container). Stops gracefully on SIGTERM/SIGINT
 * after the current iteration. Queue processing is added in Milestone 1.
 */
#[AsCommand(name: 'worker:run', description: 'Runs the background worker until SIGTERM or SIGINT')]
final class WorkerCommand extends Command
{
	private bool $running = true;


	public function __construct(
		private readonly string $heartbeatFile,
	) {
		parent::__construct();
	}


	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		pcntl_async_signals(true);
		pcntl_signal(SIGTERM, $this->stop(...));
		pcntl_signal(SIGINT, $this->stop(...));

		$output->writeln('worker started');

		while ($this->running) {
			touch($this->heartbeatFile);
			sleep(1);
		}

		$output->writeln('worker stopped');

		return self::SUCCESS;
	}


	private function stop(): void
	{
		$this->running = false;
	}
}
