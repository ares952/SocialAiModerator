<?php

declare(strict_types=1);

namespace App\Console;

use App\Translation\TranslationImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


#[AsCommand(name: 'translations:import', description: 'Imports data/language_<code>.json into the translations table')]
final class TranslationsImportCommand extends Command
{
	public function __construct(
		private readonly TranslationImporter $importer,
	) {
		parent::__construct();
	}


	protected function configure(): void
	{
		$this->addOption('force', null, InputOption::VALUE_NONE, 'Import even when a file did not change since the last import');
		$this->addOption('check', null, InputOption::VALUE_NONE, 'Only list languages with a pending import; exit code 1 if any');
	}


	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		if ($input->getOption('check') === true) {
			$pending = $this->importer->pending();
			$output->writeln($pending === [] ? 'translations up to date' : 'pending: ' . implode(', ', $pending));

			return $pending === [] ? self::SUCCESS : self::FAILURE;
		}

		foreach ($this->importer->import($input->getOption('force') === true) as $language => $result) {
			$output->writeln($result->skipped
				? "$language: unchanged"
				: "$language: $result->changed changed, $result->removed removed");
		}

		return self::SUCCESS;
	}
}
