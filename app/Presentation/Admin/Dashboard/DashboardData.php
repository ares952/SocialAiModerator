<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Dashboard;

use App\Monitoring\MetricRange;
use App\Monitoring\Metrics;
use App\Monitoring\MetricSeries;
use App\Monitoring\Outcome;
use App\Monitoring\Scope;
use App\Monitoring\Timeline;
use App\Presentation\Admin\Charts;
use App\Translation\Translator;


/**
 * Template data of the dashboard for a period and a scope: number tiles with sparklines and the
 * change against the previous period, and the charts (definitions from Charts).
 *
 * @phpstan-import-type Chart from Charts
 * @phpstan-type Data array{tiles: list<array<string, mixed>>, totals: array{events: int, decisions: int, share: ?int, average: ?float}, tokens: list<array{model: string, calls: int, input: int, output: int}>, charts: array<string, Chart>}
 */
final class DashboardData
{
	public function __construct(
		private readonly Metrics $metrics,
		private readonly MetricSeries $series,
		private readonly Charts $charts,
		private readonly Translator $translator,
	) {
	}


	/**
	 * @return Data
	 */
	public function build(MetricRange $range, Scope $scope, \DateTimeImmutable $now, \DateTimeZone $zone): array
	{
		$compared = Timeline::create($range, $now, $zone, 2);
		$timeline = $compared->current();
		$labels = $this->charts->labels($timeline);
		$events = $this->series->events($compared, $scope);
		$decisions = $this->series->decisions($compared, $scope);
		$actions = $this->series->actions($compared, $scope);
		$received = array_map(
			fn(int $comments, int $messages, int $other): int => $comments + $messages + $other,
			$events['comments'],
			$events['messages'],
			$events['other'],
		);
		$approvals = $this->metrics->approvals($scope);
		$failures = array_sum($compared->last($actions['failures']));
		$account = $scope->accountId === null ? [] : [
			'account' => (string) $scope->accountId,
		];

		$decided = array_sum($compared->last($decisions['decisions']));
		$escalated = array_sum($compared->last($decisions['escalations']));
		$average = $decided === 0 ? null : array_sum(array_map(
			fn(?float $value, int $count): float => ($value ?? 0.0) * $count,
			$compared->last($decisions['average']),
			$compared->last($decisions['decisions']),
		)) / $decided;

		return [
			'tiles' => [
				$this->tile('events', 'events', 'primary', $received, $compared, $labels, [':Admin:Events:', $account]),
				[
					'key' => 'approvals',
					'icon' => 'review',
					'color' => 'warning',
					'value' => $approvals['count'],
					'change' => null,
					'oldest' => $approvals['oldest'],
					'chart' => null,
					'link' => [':Admin:Actions:', ['status' => 'pending'] + $account],
				],
				$this->tile('escalations', 'escalation', 'info', $decisions['escalations'], $compared, $labels, [':Admin:Events:', ['status' => 'review'] + $account]),
				$this->tile('hides', 'hide', 'danger', $actions['hides'], $compared, $labels, [':Admin:Actions:', ['type' => 'hide'] + $account]),
				$this->tile('replies', 'reply', 'success', $actions['replies'], $compared, $labels, [':Admin:Actions:', ['type' => 'reply'] + $account]),
				$this->tile('failures', 'failure', $failures > 0 ? 'danger' : 'secondary', $actions['failures'], $compared, $labels, [':Admin:Actions:', ['status' => 'failed'] + $account]),
			],
			'totals' => [
				'events' => array_sum($compared->last($received)),
				'decisions' => $decided,
				'share' => $decided === 0 ? null : (int) round($escalated / $decided * 100),
				'average' => $average === null ? null : round($average, 1),
			],
			'tokens' => $this->metrics->tokens($timeline, $scope),
			'charts' => [
				'traffic' => $this->charts->chart('bar', $labels, [
					$this->charts->dataset('dashboard.series.comments', 'primary', $compared->last($events['comments'])),
					$this->charts->dataset('dashboard.series.messages', 'info', $compared->last($events['messages'])),
					$this->charts->dataset('dashboard.series.other', 'muted', $compared->last($events['other'])),
					$this->charts->dataset('dashboard.series.decisions', 'warning', $compared->last($decisions['decisions'])) + [
						'type' => 'line',
					],
				], [
					'stacked' => true,
				]),
				'decisions' => $this->charts->distribution('doughnut', $this->metrics->decisionActions($timeline, $scope), 'action.', Charts::ActionColors),
				'categories' => $this->charts->distribution('bar', $this->metrics->categories($timeline, $scope), 'category.'),
				'outcomes' => $this->outcomes($this->metrics->outcomes($timeline, $scope)),
				'classification' => $this->charts->chart('line', $labels, [
					$this->charts->dataset('dashboard.series.average', 'primary', $compared->last($decisions['average'])),
					$this->charts->dataset('dashboard.series.longest', 'danger', $compared->last($decisions['longest'])),
				], [
					'unit' => 's',
				], $this->translator->translate('dashboard.chart.classification')),
				'tokens' => $this->charts->chart('bar', $labels, $this->charts->named($this->series->tokens($timeline, $scope)), [
					'stacked' => true,
				]),
				'platforms' => $this->charts->distribution('doughnut', $this->metrics->platforms($timeline, $scope), 'platform.', [
					'facebook' => 'facebook',
					'instagram' => 'instagram',
				]),
			],
		];
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
		$datasets = array_map(
			fn(Outcome $outcome) => $this->charts->dataset("chart.outcome.{$outcome->value}", Charts::OutcomeColors[$outcome->value], array_column($outcomes, $outcome->value)),
			Outcome::cases(),
		);

		return $this->charts->chart('bar', $labels, $datasets, [
			'stacked' => true,
			'horizontal' => true,
		]);
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
			'chart' => $this->charts->chart('line', $labels, [
				$this->charts->dataset("dashboard.tile.$key", 'current', $compared->last($values)),
			], [
				'sparkline' => true,
			]),
			'link' => $link,
		];
	}
}
