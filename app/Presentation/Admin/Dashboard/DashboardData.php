<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Dashboard;

use App\Monitoring\MetricRange;
use App\Monitoring\Metrics;
use App\Monitoring\MetricSeries;
use App\Monitoring\Timeline;
use App\Translation\Translator;


/**
 * Template data of the dashboard: number tiles with sparklines and the change against the
 * previous period, and chart definitions for www/assets/admin/charts.js. Labels are translated
 * here; a dataset color is a key of the CSS variables --sa-chart-<color> in admin.css ('current'
 * is the text color of the tile).
 *
 * @phpstan-type Dataset array{label: string, color: string, data: list<int|float|null>, type?: string, colors?: list<string>}
 * @phpstan-type Chart array{type: string, labels: list<string>, datasets: list<Dataset>, summary: string, stacked?: bool, horizontal?: bool, sparkline?: bool, unit?: string}
 */
final class DashboardData
{
	private const ActionColors = [
		'ignore' => 'muted',
		'keep' => 'success',
		'reply' => 'info',
		'hide' => 'danger',
		'review' => 'warning',
		'handoff' => 'primary',
	];

	private const OutcomeColors = [
		'pending' => 'warning',
		'approved' => 'info',
		'sent' => 'success',
		'rejected' => 'secondary',
		'blocked' => 'muted',
		'failed' => 'danger',
	];

	private const ModelColors = [
		'primary',
		'teal',
		'warning',
		'indigo',
		'info',
		'danger',
	];


	public function __construct(
		private readonly Metrics $metrics,
		private readonly MetricSeries $series,
		private readonly Translator $translator,
	) {
	}


	/**
	 * @return array<string, mixed> template parameters
	 */
	public function build(MetricRange $range, \DateTimeImmutable $now, \DateTimeZone $zone): array
	{
		$compared = Timeline::create($range, $now, $zone, 2);
		$timeline = $compared->current();
		$labels = $this->labels($timeline);
		$events = $this->series->events($compared);
		$decisions = $this->series->decisions($compared);
		$actions = $this->series->actions($compared);
		$received = array_map(
			fn(int $comments, int $messages, int $other): int => $comments + $messages + $other,
			$events['comments'],
			$events['messages'],
			$events['other'],
		);
		$approvals = $this->metrics->approvals();
		$failures = array_sum($compared->last($actions['failures']));

		$decided = array_sum($compared->last($decisions['decisions']));
		$escalated = array_sum($compared->last($decisions['escalations']));
		$average = $decided === 0 ? null : array_sum(array_map(
			fn(?float $value, int $count): float => ($value ?? 0.0) * $count,
			$compared->last($decisions['average']),
			$compared->last($decisions['decisions']),
		)) / $decided;

		return [
			'tiles' => [
				$this->tile('events', 'events', 'primary', $received, $compared, $labels, [':Admin:Events:', []]),
				[
					'key' => 'approvals',
					'icon' => 'review',
					'color' => 'warning',
					'value' => $approvals['count'],
					'change' => null,
					'oldest' => $approvals['oldest'],
					'chart' => null,
					'link' => [':Admin:Actions:', ['status' => 'pending']],
				],
				$this->tile('escalations', 'escalation', 'info', $decisions['escalations'], $compared, $labels, [':Admin:Events:', ['status' => 'review']]),
				$this->tile('hides', 'hide', 'danger', $actions['hides'], $compared, $labels, [':Admin:Actions:', []]),
				$this->tile('replies', 'reply', 'success', $actions['replies'], $compared, $labels, [':Admin:Actions:', []]),
				$this->tile('failures', 'failure', $failures > 0 ? 'danger' : 'secondary', $actions['failures'], $compared, $labels, [':Admin:Actions:', ['status' => 'failed']]),
			],
			'traffic' => $this->chart('bar', $labels, [
				$this->dataset('dashboard.series.comments', 'primary', $compared->last($events['comments'])),
				$this->dataset('dashboard.series.messages', 'info', $compared->last($events['messages'])),
				$this->dataset('dashboard.series.other', 'muted', $compared->last($events['other'])),
				$this->dataset('dashboard.series.decisions', 'warning', $compared->last($decisions['decisions'])) + [
					'type' => 'line',
				],
			], [
				'stacked' => true,
			]),
			'totals' => [
				'events' => array_sum($compared->last($received)),
				'decisions' => $decided,
				'share' => $decided === 0 ? null : (int) round($escalated / $decided * 100),
				'average' => $average === null ? null : round($average, 1),
			],
			'decisionChart' => $this->distribution('doughnut', $this->metrics->decisionActions($timeline), 'action.', self::ActionColors),
			'categoryChart' => $this->distribution('bar', $this->metrics->categories($timeline), 'category.', []),
			'outcomeChart' => $this->outcomes($this->metrics->outcomes($timeline)),
			'classificationChart' => $this->chart('line', $labels, [
				$this->dataset('dashboard.series.average', 'primary', $compared->last($decisions['average'])),
				$this->dataset('dashboard.series.longest', 'danger', $compared->last($decisions['longest'])),
			], [
				'unit' => 's',
			], $this->translator->translate('dashboard.chart.classification')),
			'tokenChart' => $this->tokens($this->series->tokens($timeline), $labels),
			'tokens' => $this->metrics->tokens($timeline),
			'platformChart' => $this->distribution('doughnut', $this->metrics->platforms($timeline), 'platform.', [
				'facebook' => 'facebook',
				'instagram' => 'instagram',
			]),
		];
	}


	/**
	 * @param list<int> $values one value per bucket of the compared timeline
	 * @param list<string> $labels
	 * @param array{string, array<string, string>} $link
	 * @return array{key: string, icon: string, color: string, value: int, change: ?int, oldest: null, chart: Chart, link: array{string, array<string, string>}}
	 */
	private function tile(string $key, string $icon, string $color, array $values, Timeline $compared, array $labels, array $link): array
	{
		$value = array_sum($compared->last($values));
		$previous = array_sum($compared->previous($values));

		return [
			'key' => $key,
			'icon' => $icon,
			'color' => $color,
			'value' => $value,
			'change' => $previous === 0 ? null : (int) round(($value - $previous) / $previous * 100),
			'oldest' => null,
			'chart' => $this->chart('line', $labels, [
				$this->dataset("dashboard.tile.$key", 'current', $compared->last($values)),
			], [
				'sparkline' => true,
			]),
			'link' => $link,
		];
	}


	/**
	 * A doughnut or a horizontal bar chart of counts; labels are translation keys with the prefix.
	 *
	 * @param array<string, int> $counts value => count
	 * @param array<string, string> $colors value => color; empty = one color for all
	 * @return Chart
	 */
	private function distribution(string $type, array $counts, string $prefix, array $colors): array
	{
		$labels = array_map(
			fn(string $value): string => $this->translator->translate($value === '' ? 'dashboard.chart.unclassified' : $prefix . $value),
			array_map(strval(...), array_keys($counts)),
		);
		$dataset = $this->dataset('dashboard.series.count', 'primary', array_values($counts));
		if ($colors !== []) {
			$dataset['colors'] = array_map(
				fn(string $value): string => $colors[$value] ?? 'muted',
				array_map(strval(...), array_keys($counts)),
			);
		}

		return $this->chart($type, $labels, [$dataset], [
			'horizontal' => $type === 'bar',
		]);
	}


	/**
	 * Interventions by type, stacked by outcome.
	 *
	 * @param array<string, array<string, int>> $outcomes type => outcome => count
	 * @return Chart
	 */
	private function outcomes(array $outcomes): array
	{
		$labels = array_map(
			fn(string $type): string => $this->translator->translate("action.type.$type"),
			array_map(strval(...), array_keys($outcomes)),
		);
		$datasets = [];
		foreach (array_keys(Metrics::Outcomes) as $outcome) {
			$datasets[] = $this->dataset("dashboard.outcome.$outcome", self::OutcomeColors[$outcome], array_column($outcomes, $outcome));
		}

		return $this->chart('bar', $labels, $datasets, [
			'stacked' => true,
			'horizontal' => true,
		]);
	}


	/**
	 * @param array<string, list<int>> $tokens model => tokens per bucket
	 * @param list<string> $labels
	 * @return Chart
	 */
	private function tokens(array $tokens, array $labels): array
	{
		$datasets = [];
		foreach (array_keys($tokens) as $index => $model) {
			$datasets[] = [
				'label' => (string) $model,
				'color' => self::ModelColors[$index % count(self::ModelColors)],
				'data' => $tokens[$model],
			];
		}

		return $this->chart('bar', $labels, $datasets, [
			'stacked' => true,
		]);
	}


	/**
	 * @param list<int|float|null> $data
	 * @return Dataset
	 */
	private function dataset(string $key, string $color, array $data): array
	{
		return [
			'label' => $this->translator->translate($key),
			'color' => $color,
			'data' => $data,
		];
	}


	/**
	 * @param list<string> $labels
	 * @param list<Dataset> $datasets
	 * @param array{stacked?: bool, horizontal?: bool, sparkline?: bool, unit?: string} $options
	 * @return Chart
	 */
	private function chart(string $type, array $labels, array $datasets, array $options, ?string $summary = null): array
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
	 * @return list<string> local hour or day of each bucket
	 */
	private function labels(Timeline $timeline): array
	{
		$format = match (true) {
			$timeline->range->hourly() => 'H:00',
			$this->translator->getLanguage() === 'cs' => 'j. n.',
			default => 'M j',
		};

		return array_map(fn(\DateTimeImmutable $start): string => $start->format($format), $timeline->starts);
	}


	private function number(float $value): string
	{
		return $this->translator->getLanguage() === 'cs'
			? number_format($value, 0, ',', "\u{a0}")
			: number_format($value);
	}
}
