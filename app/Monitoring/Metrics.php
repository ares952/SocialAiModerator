<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Outbox\ActionStatus;
use Nette\Database\Explorer;


/**
 * Breakdowns of the dashboard over the period of a Timeline (specification §4.3, §8.4): AI
 * decisions by action and category, outcomes of interventions, events by platform and token usage
 * per model; plus the approvals waiting right now. Results never contain customer content.
 */
final class Metrics
{
	/** Outcome of an intervention on the dashboard => action statuses. */
	public const Outcomes = [
		'pending' => [ActionStatus::Pending],
		'approved' => [ActionStatus::Approved, ActionStatus::Sending],
		'sent' => [ActionStatus::Sent],
		'rejected' => [],
		'blocked' => [],
		'failed' => [ActionStatus::Failed, ActionStatus::Unknown],
	];


	public function __construct(
		private readonly Explorer $database,
	) {
	}


	/**
	 * @return array<string, int> App\Moderation\Action value => decisions, most frequent first
	 */
	public function decisionActions(Timeline $timeline): array
	{
		return $this->database->query(
			'SELECT action, COUNT(*) AS cnt FROM moderation_decisions WHERE created_at >= ? AND created_at < ?
			GROUP BY action ORDER BY cnt DESC, action',
			$timeline->since,
			$timeline->until,
		)->fetchPairs('action', 'cnt');
	}


	/**
	 * @return array<string, int> App\Moderation\Category value, '' when the classification failed,
	 *     => decisions, most frequent first
	 */
	public function categories(Timeline $timeline): array
	{
		return $this->database->query(
			"SELECT COALESCE(category, '') AS category, COUNT(*) AS cnt FROM moderation_decisions
			WHERE created_at >= ? AND created_at < ?
			GROUP BY category ORDER BY cnt DESC, category",
			$timeline->since,
			$timeline->until,
		)->fetchPairs('category', 'cnt');
	}


	/**
	 * Interventions by type and outcome (keys of Outcomes). A blocked action counts as rejected
	 * when a moderator rejected it, otherwise as blocked by the rules.
	 *
	 * @return array<string, array<string, int>> App\Outbox\ActionType value => outcome => actions
	 */
	public function outcomes(Timeline $timeline): array
	{
		$rows = $this->database->query(
			'SELECT type, status, JSON_CONTAINS(COALESCE(reasons_json, JSON_ARRAY()), ?) AS rejected, COUNT(*) AS cnt
			FROM moderation_actions WHERE created_at >= ? AND created_at < ?
			GROUP BY type, status, rejected
			ORDER BY type',
			'"rejected"',
			$timeline->since,
			$timeline->until,
		)->fetchAll();

		$outcomes = [];
		foreach ($rows as $row) {
			$status = ActionStatus::from((string) $row->status);
			$outcome = $status === ActionStatus::Blocked
				? ((bool) $row->rejected ? 'rejected' : 'blocked')
				: self::outcome($status);
			$outcomes[(string) $row->type] ??= array_fill_keys(array_keys(self::Outcomes), 0);
			$outcomes[(string) $row->type][$outcome] += (int) $row->cnt;
		}

		return $outcomes;
	}


	/**
	 * @return array<string, int> App\Meta\Platform value => received events
	 */
	public function platforms(Timeline $timeline): array
	{
		return $this->database->query(
			'SELECT platform, COUNT(*) AS cnt FROM social_events WHERE received_at >= ? AND received_at < ?
			GROUP BY platform ORDER BY platform',
			$timeline->since,
			$timeline->until,
		)->fetchPairs('platform', 'cnt');
	}


	/**
	 * Token usage of the classifier, the moderation endpoint excluded, and of reply drafts, per model.
	 *
	 * @return list<array{model: string, calls: int, input: int, output: int}>
	 */
	public function tokens(Timeline $timeline): array
	{
		$rows = $this->database->query(
			'SELECT model, COUNT(*) AS calls, SUM(input_tokens) AS input, SUM(output_tokens) AS output FROM (
				SELECT model, input_tokens, output_tokens FROM moderation_decisions
				WHERE created_at >= ? AND created_at < ? AND input_tokens IS NOT NULL
				UNION ALL
				SELECT model, input_tokens, output_tokens FROM moderation_actions
				WHERE created_at >= ? AND created_at < ? AND input_tokens IS NOT NULL
			) usage_rows
			GROUP BY model
			ORDER BY model',
			$timeline->since,
			$timeline->until,
			$timeline->since,
			$timeline->until,
		)->fetchAll();

		return array_map(fn($row) => [
			'model' => (string) $row->model,
			'calls' => (int) $row->calls,
			'input' => (int) $row->input,
			'output' => (int) $row->output,
		], $rows);
	}


	/**
	 * Interventions waiting for a human decision now, and when the oldest of them was created (UTC).
	 *
	 * @return array{count: int, oldest: ?\DateTimeImmutable}
	 */
	public function approvals(): array
	{
		$row = $this->database->query(
			'SELECT COUNT(*) AS cnt, MIN(created_at) AS oldest FROM moderation_actions WHERE status = ?',
			ActionStatus::Pending->value,
		)->fetch();

		return [
			'count' => (int) $row?->cnt,
			'oldest' => $row?->oldest instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($row->oldest) : null,
		];
	}


	private static function outcome(ActionStatus $status): string
	{
		foreach (self::Outcomes as $outcome => $statuses) {
			if (in_array($status, $statuses, true)) {
				return $outcome;
			}
		}

		throw new \LogicException("Action status {$status->value} has no dashboard outcome.");
	}
}
