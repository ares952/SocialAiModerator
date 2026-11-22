<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Meta\EventType;
use App\Moderation\Action;
use App\Outbox\ActionStatus;
use App\Outbox\ActionType;
use App\Queue\JobStatus;
use Nette\Database\Explorer;
use Nette\Database\Row;


/**
 * Time series of the charts (specification §4.3, §8.4), one value per bucket of a Timeline, for
 * the traffic of a Scope. The database groups by UTC hour; the hours are folded into the buckets
 * here, empty buckets stay zero.
 */
final class MetricSeries
{
	private const Hour = "DATE_FORMAT(%s, '%%Y-%%m-%%d %%H:00:00')";


	public function __construct(
		private readonly Explorer $database,
	) {
	}


	/**
	 * Received events by type.
	 *
	 * @return array{comments: list<int>, messages: list<int>, other: list<int>}
	 */
	public function events(Timeline $timeline, Scope $scope): array
	{
		$series = $this->sums($timeline, $this->database->query(
			'SELECT ' . sprintf(self::Hour, 'e.received_at') . ' AS hour, COUNT(*) AS total,
				SUM(e.event_type LIKE ?) AS comments, SUM(e.event_type = ?) AS messages
			FROM social_events e WHERE e.received_at >= ? AND e.received_at < ? AND ?and
			GROUP BY hour',
			'comment.%',
			EventType::MessageReceived->value,
			$timeline->since,
			$timeline->until,
			$scope->events('e'),
		)->fetchAll(), [
			'total',
			'comments',
			'messages',
		]);

		return [
			'comments' => $series['comments'],
			'messages' => $series['messages'],
			'other' => array_map(
				fn(int $total, int $comments, int $messages): int => $total - $comments - $messages,
				$series['total'],
				$series['comments'],
				$series['messages'],
			),
		];
	}


	/**
	 * Received events per social account; events of unknown accounts are left out.
	 *
	 * @return array<string, list<int>> account name => events per bucket, sorted by name
	 */
	public function accounts(Timeline $timeline): array
	{
		return $this->grouped($timeline, $this->database->query(
			'SELECT ' . sprintf(self::Hour, 'e.received_at') . ' AS hour, a.name AS name, COUNT(*) AS value
			FROM social_events e
			JOIN social_accounts a ON a.id = e.account_id
			WHERE e.received_at >= ? AND e.received_at < ?
			GROUP BY hour, a.id, a.name
			ORDER BY a.name',
			$timeline->since,
			$timeline->until,
		)->fetchAll());
	}


	/**
	 * AI decisions, escalations to a human, and the average and longest time from receiving the
	 * event to the decision in seconds (null in a bucket without decisions).
	 *
	 * @return array{decisions: list<int>, escalations: list<int>, average: list<float|null>, longest: list<float|null>}
	 */
	public function decisions(Timeline $timeline, Scope $scope): array
	{
		$rows = $this->database->query(
			'SELECT ' . sprintf(self::Hour, 'd.created_at') . ' AS hour, COUNT(*) AS decisions, SUM(d.action IN (?)) AS escalations,
				SUM(TIMESTAMPDIFF(MICROSECOND, e.received_at, d.created_at)) / 1000000 AS seconds,
				MAX(TIMESTAMPDIFF(MICROSECOND, e.received_at, d.created_at)) / 1000000 AS longest
			FROM moderation_decisions d
			JOIN social_events e ON e.id = d.event_id
			WHERE d.created_at >= ? AND d.created_at < ? AND ?and
			GROUP BY hour',
			[
				Action::Handoff->value,
				Action::Review->value,
			],
			$timeline->since,
			$timeline->until,
			$scope->events('e'),
		)->fetchAll();
		$series = $this->sums($timeline, $rows, [
			'decisions',
			'escalations',
		]);

		$seconds = array_fill(0, count($timeline->starts), 0.0);
		$longest = array_fill(0, count($timeline->starts), null);
		foreach ($rows as $row) {
			$position = $timeline->position((string) $row->hour);
			if ($position !== null) {
				$seconds[$position] += (float) $row->seconds;
				$longest[$position] = max($longest[$position] ?? 0.0, (float) $row->longest);
			}
		}

		return [
			'decisions' => $series['decisions'],
			'escalations' => $series['escalations'],
			'average' => array_map(
				fn(float $sum, int $count): ?float => $count === 0 ? null : round($sum / $count, 1),
				$seconds,
				$series['decisions'],
			),
			'longest' => array_values(array_map(
				fn(?float $value): ?float => $value === null ? null : round($value, 1),
				$longest,
			)),
		];
	}


	/**
	 * Proposed hides, reply drafts, and failures: actions that failed or ended with an unknown
	 * result, plus failed queue jobs when the scope covers everything.
	 *
	 * @return array{hides: list<int>, replies: list<int>, failures: list<int>}
	 */
	public function actions(Timeline $timeline, Scope $scope): array
	{
		$actions = $this->sums($timeline, $this->database->query(
			'SELECT ' . sprintf(self::Hour, 'a.created_at') . ' AS hour, SUM(a.type = ?) AS hides,
				SUM(a.type = ? AND a.text_encrypted IS NOT NULL) AS replies, SUM(a.status IN (?)) AS failures
			FROM moderation_actions a ' . Scope::ActionAccount . '
			WHERE a.created_at >= ? AND a.created_at < ? AND ?and
			GROUP BY hour',
			ActionType::Hide->value,
			ActionType::Reply->value,
			[
				ActionStatus::Failed->value,
				ActionStatus::Unknown->value,
			],
			$timeline->since,
			$timeline->until,
			$scope->accounts('s'),
		)->fetchAll(), [
			'hides',
			'replies',
			'failures',
		]);
		$jobs = $scope->all() ? $this->sums($timeline, $this->database->query(
			'SELECT ' . sprintf(self::Hour, 'updated_at') . ' AS hour, COUNT(*) AS failures
			FROM queue_jobs WHERE status = ? AND updated_at >= ? AND updated_at < ?
			GROUP BY hour',
			JobStatus::Failed->value,
			$timeline->since,
			$timeline->until,
		)->fetchAll(), [
			'failures',
		]) : [
			'failures' => $timeline->zeros(),
		];

		return [
			'hides' => $actions['hides'],
			'replies' => $actions['replies'],
			'failures' => array_map(
				fn(int $actionFailures, int $jobFailures): int => $actionFailures + $jobFailures,
				$actions['failures'],
				$jobs['failures'],
			),
		];
	}


	/**
	 * Interventions created in each bucket by their current outcome.
	 *
	 * @return array<string, list<int>> Outcome value => interventions per bucket, every outcome present
	 */
	public function outcomes(Timeline $timeline, Scope $scope, ?ActionType $type = null): array
	{
		$rows = $this->database->query(
			'SELECT ' . sprintf(self::Hour, 'a.created_at') . ' AS hour, a.status,
				JSON_CONTAINS(COALESCE(a.reasons_json, JSON_ARRAY()), ?) AS rejected, COUNT(*) AS cnt
			FROM moderation_actions a ' . Scope::ActionAccount . '
			WHERE a.created_at >= ? AND a.created_at < ? AND ?and
			GROUP BY hour, a.status, rejected',
			'"rejected"',
			$timeline->since,
			$timeline->until,
			$scope->accounts('s') + ($type === null ? [] : [
				'a.type' => $type->value,
			]),
		)->fetchAll();

		$series = array_fill_keys(array_map(fn(Outcome $outcome): string => $outcome->value, Outcome::cases()), $timeline->zeros());
		foreach ($rows as $row) {
			$position = $timeline->position((string) $row->hour);
			if ($position !== null) {
				$outcome = Outcome::of(ActionStatus::from((string) $row->status), (bool) $row->rejected);
				$series[$outcome->value][$position] += (int) $row->cnt;
			}
		}

		return array_map(array_values(...), $series);
	}


	/**
	 * Input and output tokens of the classifier and of reply drafts per model.
	 *
	 * @return array<string, list<int>> model => tokens per bucket, models sorted by name
	 */
	public function tokens(Timeline $timeline, Scope $scope): array
	{
		return $this->grouped($timeline, $this->database->query(
			'SELECT hour, name, SUM(tokens) AS value FROM (
				SELECT ' . sprintf(self::Hour, 'd.created_at') . ' AS hour, d.model AS name, d.input_tokens + COALESCE(d.output_tokens, 0) AS tokens
				FROM moderation_decisions d
				JOIN social_events e ON e.id = d.event_id
				WHERE d.created_at >= ? AND d.created_at < ? AND d.input_tokens IS NOT NULL AND ?and
				UNION ALL
				SELECT ' . sprintf(self::Hour, 'a.created_at') . ' AS hour, a.model AS name, a.input_tokens + COALESCE(a.output_tokens, 0) AS tokens
				FROM moderation_actions a ' . Scope::ActionAccount . '
				WHERE a.created_at >= ? AND a.created_at < ? AND a.input_tokens IS NOT NULL AND ?and
			) usage_rows
			GROUP BY hour, name
			ORDER BY name',
			$timeline->since,
			$timeline->until,
			$scope->events('e'),
			$timeline->since,
			$timeline->until,
			$scope->accounts('s'),
		)->fetchAll());
	}


	/**
	 * @param list<Row> $rows rows with the columns `hour`, `name` and `value`, ordered by name
	 * @return array<string, list<int>> name => sum per bucket
	 */
	private function grouped(Timeline $timeline, array $rows): array
	{
		$series = [];
		foreach ($rows as $row) {
			$name = (string) $row->name;
			$series[$name] ??= $timeline->zeros();
			$position = $timeline->position((string) $row->hour);
			if ($position !== null) {
				$series[$name][$position] += (int) $row->value;
			}
		}

		return array_map(array_values(...), $series);
	}


	/**
	 * @param list<Row> $rows rows with the column `hour` and the given columns
	 * @param list<string> $columns
	 * @return array<string, list<int>> column => sum per bucket
	 */
	private function sums(Timeline $timeline, array $rows, array $columns): array
	{
		$series = array_fill_keys($columns, $timeline->zeros());
		foreach ($rows as $row) {
			$position = $timeline->position((string) $row->hour);
			if ($position === null) {
				continue;
			}
			foreach ($columns as $column) {
				$series[$column][$position] += (int) $row->$column;
			}
		}

		return array_map(array_values(...), $series);
	}
}
