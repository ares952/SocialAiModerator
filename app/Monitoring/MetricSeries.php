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
 * Time series of the dashboard charts (specification §4.3, §8.4), one value per bucket of a
 * Timeline. The database groups by UTC hour; the hours are folded into the buckets here, empty
 * buckets stay zero.
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
	public function events(Timeline $timeline): array
	{
		$series = $this->sums($timeline, $this->database->query(
			'SELECT ' . sprintf(self::Hour, 'received_at') . ' AS hour, COUNT(*) AS total,
				SUM(event_type LIKE ?) AS comments, SUM(event_type = ?) AS messages
			FROM social_events WHERE received_at >= ? AND received_at < ?
			GROUP BY hour',
			'comment.%',
			EventType::MessageReceived->value,
			$timeline->since,
			$timeline->until,
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
	 * AI decisions, escalations to a human, and the average and longest time from receiving the
	 * event to the decision in seconds (null in a bucket without decisions).
	 *
	 * @return array{decisions: list<int>, escalations: list<int>, average: list<float|null>, longest: list<float|null>}
	 */
	public function decisions(Timeline $timeline): array
	{
		$rows = $this->database->query(
			'SELECT ' . sprintf(self::Hour, 'd.created_at') . ' AS hour, COUNT(*) AS decisions, SUM(d.action IN (?)) AS escalations,
				SUM(TIMESTAMPDIFF(MICROSECOND, e.received_at, d.created_at)) / 1000000 AS seconds,
				MAX(TIMESTAMPDIFF(MICROSECOND, e.received_at, d.created_at)) / 1000000 AS longest
			FROM moderation_decisions d
			JOIN social_events e ON e.id = d.event_id
			WHERE d.created_at >= ? AND d.created_at < ?
			GROUP BY hour',
			[
				Action::Handoff->value,
				Action::Review->value,
			],
			$timeline->since,
			$timeline->until,
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
	 * Proposed hides, reply drafts, actions that failed or ended with an unknown result, and
	 * failed queue jobs.
	 *
	 * @return array{hides: list<int>, replies: list<int>, failures: list<int>}
	 */
	public function actions(Timeline $timeline): array
	{
		$actions = $this->sums($timeline, $this->database->query(
			'SELECT ' . sprintf(self::Hour, 'created_at') . ' AS hour, SUM(type = ?) AS hides,
				SUM(type = ? AND text_encrypted IS NOT NULL) AS replies, SUM(status IN (?)) AS failures
			FROM moderation_actions WHERE created_at >= ? AND created_at < ?
			GROUP BY hour',
			ActionType::Hide->value,
			ActionType::Reply->value,
			[
				ActionStatus::Failed->value,
				ActionStatus::Unknown->value,
			],
			$timeline->since,
			$timeline->until,
		)->fetchAll(), [
			'hides',
			'replies',
			'failures',
		]);
		$jobs = $this->sums($timeline, $this->database->query(
			'SELECT ' . sprintf(self::Hour, 'updated_at') . ' AS hour, COUNT(*) AS failures
			FROM queue_jobs WHERE status = ? AND updated_at >= ? AND updated_at < ?
			GROUP BY hour',
			JobStatus::Failed->value,
			$timeline->since,
			$timeline->until,
		)->fetchAll(), [
			'failures',
		]);

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
	 * Input and output tokens of the classifier and of reply drafts per model.
	 *
	 * @return array<string, list<int>> model => tokens per bucket, models sorted by name
	 */
	public function tokens(Timeline $timeline): array
	{
		$rows = $this->database->query(
			'SELECT hour, model, SUM(tokens) AS tokens FROM (
				SELECT ' . sprintf(self::Hour, 'created_at') . ' AS hour, model, input_tokens + COALESCE(output_tokens, 0) AS tokens
				FROM moderation_decisions WHERE created_at >= ? AND created_at < ? AND input_tokens IS NOT NULL
				UNION ALL
				SELECT ' . sprintf(self::Hour, 'created_at') . ' AS hour, model, input_tokens + COALESCE(output_tokens, 0) AS tokens
				FROM moderation_actions WHERE created_at >= ? AND created_at < ? AND input_tokens IS NOT NULL
			) usage_rows
			GROUP BY hour, model
			ORDER BY model',
			$timeline->since,
			$timeline->until,
			$timeline->since,
			$timeline->until,
		)->fetchAll();

		$series = [];
		foreach ($rows as $row) {
			$model = (string) $row->model;
			$series[$model] ??= $timeline->zeros();
			$position = $timeline->position((string) $row->hour);
			if ($position !== null) {
				$series[$model][$position] += (int) $row->tokens;
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
