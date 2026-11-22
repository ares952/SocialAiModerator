<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Monitoring\Timeline;
use App\Translation\Translator;


/**
 * Chart definitions for www/assets/admin/charts.js (docs/admin-ui.md) and their CSV export.
 * Labels are translated here; a dataset color is a key of the CSS variables --sa-chart-<color> in
 * admin.css ('current' is the text color around the chart).
 *
 * @phpstan-type Dataset array{label: string, color: string, data: list<int|float|null>, type?: string, colors?: list<string>}
 * @phpstan-type Chart array{type: string, labels: list<string>, datasets: list<Dataset>, summary: string, stacked?: bool, horizontal?: bool, sparkline?: bool, unit?: string}
 */
final class Charts
{
	/** Colors of AI decision actions (App\Moderation\Action). */
	public const ActionColors = [
		'ignore' => 'muted',
		'keep' => 'success',
		'reply' => 'info',
		'hide' => 'danger',
		'review' => 'warning',
		'handoff' => 'primary',
	];

	/** Colors of intervention outcomes (App\Monitoring\Outcome). */
	public const OutcomeColors = [
		'pending' => 'warning',
		'approved' => 'info',
		'sent' => 'success',
		'rejected' => 'secondary',
		'blocked' => 'muted',
		'failed' => 'danger',
	];

	/** Colors of series without a meaning of their own (models, accounts), in this order. */
	private const Palette = [
		'primary',
		'teal',
		'warning',
		'indigo',
		'info',
		'danger',
	];

	/** Characters that make a spreadsheet read a cell as a formula. */
	private const FormulaStart = [
		'=',
		'+',
		'-',
		'@',
		"\t",
		"\r",
	];


	public function __construct(
		private readonly Translator $translator,
	) {
	}


	/**
	 * @param list<string> $labels
	 * @param list<Dataset> $datasets
	 * @param array{stacked?: bool, horizontal?: bool, sparkline?: bool, unit?: string} $options
	 * @return Chart
	 */
	public function chart(string $type, array $labels, array $datasets, array $options = [], ?string $summary = null): array
	{
		$summary ??= implode(', ', array_map(
			fn(array $dataset): string => $dataset['label'] . ': ' . $this->number(array_sum(array_map(floatval(...), $dataset['data']))),
			$datasets,
		));

		return [
			'type' => $type,
			'labels' => $labels,
			'datasets' => $datasets,
			'summary' => $summary,
		] + $options;
	}


	/**
	 * @param list<int|float|null> $data
	 * @return Dataset
	 */
	public function dataset(string $key, string $color, array $data): array
	{
		return [
			'label' => $this->translator->translate($key),
			'color' => $color,
			'data' => $data,
		];
	}


	/**
	 * Datasets labelled by data (model or account names) in the palette colors.
	 *
	 * @param array<string, list<int>> $series name => values
	 * @return list<Dataset>
	 */
	public function named(array $series): array
	{
		$datasets = [];
		foreach (array_keys($series) as $index => $name) {
			$datasets[] = [
				'label' => (string) $name,
				'color' => self::Palette[$index % count(self::Palette)],
				'data' => $series[$name],
			];
		}

		return $datasets;
	}


	/**
	 * A doughnut or a horizontal bar chart of counts; labels are translation keys with the prefix,
	 * an empty value is a failed classification.
	 *
	 * @param array<string, int> $counts value => count
	 * @param array<string, string> $colors value => color; empty = one color for all
	 * @return Chart
	 */
	public function distribution(string $type, array $counts, string $prefix, array $colors = []): array
	{
		$values = array_map(strval(...), array_keys($counts));
		$dataset = $this->dataset('chart.count', 'primary', array_values($counts));
		if ($colors !== []) {
			$dataset['colors'] = array_map(
				fn(string $value): string => $colors[$value] ?? 'muted',
				$values,
			);
		}

		return $this->chart($type, array_map(
			fn(string $value): string => $this->translator->translate($value === '' ? 'chart.unclassified' : $prefix . $value),
			$values,
		), [$dataset], [
			'horizontal' => $type === 'bar',
		]);
	}


	/**
	 * @return list<string> local hour or day of each bucket
	 */
	public function labels(Timeline $timeline): array
	{
		$format = match (true) {
			$timeline->range->hourly() => 'H:00',
			$this->translator->getLanguage() === 'cs' => 'j. n.',
			default => 'M j',
		};

		return array_map(fn(\DateTimeImmutable $start): string => $start->format($format), $timeline->starts);
	}


	/**
	 * The data of a chart as CSV for spreadsheets in the UI language: one row per label, one
	 * column per dataset; Czech uses ';' and a decimal comma, English ',' and a decimal point.
	 * UTF-8 with BOM, cells that would start a formula are prefixed with an apostrophe.
	 *
	 * @param Chart $chart
	 */
	public function csv(array $chart): string
	{
		$czech = $this->translator->getLanguage() === 'cs';
		$rows = [
			[
				$this->translator->translate('chart.column.item'),
				...array_map(fn(array $dataset): string => $dataset['label'], $chart['datasets']),
			],
		];
		foreach ($chart['labels'] as $index => $label) {
			$row = [
				$label,
			];
			foreach ($chart['datasets'] as $dataset) {
				$value = $dataset['data'][$index] ?? null;
				$row[] = match (true) {
					$value === null => '',
					is_float($value) && $czech => str_replace('.', ',', (string) $value),
					default => (string) $value,
				};
			}
			$rows[] = $row;
		}

		$stream = fopen('php://temp', 'r+') ?: throw new \RuntimeException('Cannot open a temporary stream.');
		foreach ($rows as $row) {
			fputcsv($stream, array_map(self::cell(...), $row), $czech ? ';' : ',', '"', '');
		}
		rewind($stream);
		$csv = (string) stream_get_contents($stream);
		fclose($stream);

		return "\u{FEFF}" . $csv;
	}


	private static function cell(string $value): string
	{
		return $value !== '' && in_array($value[0], self::FormulaStart, true) && !is_numeric($value) ? "'$value" : $value;
	}


	private function number(float $value): string
	{
		return $this->translator->getLanguage() === 'cs'
			? number_format($value, 0, ',', "\u{a0}")
			: number_format($value);
	}
}
