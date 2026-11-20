<?php

declare(strict_types=1);

namespace App\Console;

use App\Evaluation\EvaluationSamples;
use App\Evaluation\InvalidEvaluation;
use App\Evaluation\Sample;
use App\Meta\Platform;
use App\Moderation\Action;
use App\Moderation\Category;
use App\Moderation\ContentKind;
use Nette\Database\Explorer;
use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


/**
 * Imports labelled samples from a JSON list of objects with platform, kind, language, text,
 * expected_category, expected_action and optional note. Either all samples are imported or none.
 * Use synthetic or anonymized texts only.
 */
#[AsCommand(name: 'evaluation:import', description: 'Imports labelled evaluation samples from a JSON file')]
final class EvaluationImportCommand extends Command
{
	public function __construct(
		private readonly EvaluationSamples $samples,
		private readonly Explorer $database,
	) {
		parent::__construct();
	}


	protected function configure(): void
	{
		$this->addArgument('file', InputArgument::REQUIRED, 'JSON file with a list of samples');
	}


	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		try {
			$data = Json::decode(FileSystem::read((string) $input->getArgument('file')), forceArrays: true);
		} catch (JsonException $e) {
			$output->writeln("<error>Invalid JSON: {$e->getMessage()}</error>");

			return self::FAILURE;
		}
		if (!is_array($data) || !array_is_list($data)) {
			$output->writeln('<error>The file must contain a JSON list.</error>');

			return self::FAILURE;
		}

		$samples = [];
		foreach ($data as $index => $item) {
			try {
				$samples[] = self::sample(is_array($item) ? $item : []);
			} catch (InvalidEvaluation | \ValueError $e) {
				$output->writeln("<error>Sample #$index is invalid: {$e->getMessage()}</error>");

				return self::FAILURE;
			}
		}

		$this->database->transaction(function () use ($samples): void {
			foreach ($samples as $sample) {
				$this->samples->create($sample, operatorId: null);
			}
		});
		$output->writeln(count($samples) . ' samples imported');

		return self::SUCCESS;
	}


	/**
	 * @param array<mixed> $item
	 */
	private static function sample(array $item): Sample
	{
		$string = static fn(string $key): string => is_string($item[$key] ?? null) ? $item[$key] : '';

		return new Sample(
			platform: Platform::from($string('platform')),
			kind: ContentKind::from($string('kind')),
			language: $string('language'),
			text: $string('text'),
			expectedCategory: Category::from($string('expected_category')),
			expectedAction: Action::from($string('expected_action')),
			note: $string('note') === '' ? null : $string('note'),
		);
	}
}
