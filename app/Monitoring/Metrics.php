<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Outbox\ActionStatus;
use App\Outbox\ActionType;
use Nette\Database\Explorer;


/**
 * Breakdowns over the period of a Timeline for the traffic of a Scope (specification §4.3, §8.4):
 * AI decisions by action and category, outcomes of interventions and the reasons that stopped
 * them, events by platform and token usage per model; plus the approvals waiting right now.
 * Results never contain customer content.
 */
final class Metrics
{
	public function __construct(
		private readonly Explorer $database,
	) {
	}


	/**
	 * @return array<string, int> App\Moderation\Action value => decisions, most frequent first
	 */
	public function decisionActions(Timeline $timeline, Scope $scope): array
	{
		return $this->database->query(
			'SELECT d.action, COUNT(*) AS cnt FROM moderation_decisions d
			JOIN social_events e ON e.id = d.event_id
			WHERE d.created_at >= ? AND d.created_at < ? AND ?and
			GROUP BY d.action ORDER BY cnt DESC, d.action',
			$timeline->since,
			$timeline->until,
			$scope->events('e'),
		)->fetchPairs('action', 'cnt');
	}


	/**
	 * @return array<string, int> App\Moderation\Category value, '' when the classification failed,
	 *     => decisions, most frequent first
	 */
	public function categories(Timeline $timeline, Scope $scope): array
	{
		return $this->database->query(
			"SELECT COALESCE(d.category, '') AS category, COUNT(*) AS cnt FROM moderation_decisions d
			JOIN social_events e ON e.id = d.event_id
			WHERE d.created_at >= ? AND d.created_at < ? AND ?and
			GROUP BY d.category ORDER BY cnt DESC, category",
			$timeline->since,
			$timeline->until,
			$scope->events('e'),
		)->fetchPairs('category', 'cnt');
	}


	/**
	 * Interventions by type and outcome.
	 *
	 * @return array<string, array<string, int>> App\Outbox\ActionType value => Outcome value => actions,
	 *     every outcome present
	 */
	public function outcomes(Timeline $timeline, Scope $scope): array
	{
		$rows = $this->database->query(
			'SELECT a.type, a.status, JSON_CONTAINS(COALESCE(a.reasons_json, JSON_ARRAY()), ?) AS rejected, COUNT(*) AS cnt
			FROM moderation_actions a ' . Scope::ActionAccount . '
			WHERE a.created_at >= ? AND a.created_at < ? AND ?and
			GROUP BY a.type, a.status, rejected
			ORDER BY a.type',
			'"rejected"',
			$timeline->since,
			$timeline->until,
			$scope->accounts('s'),
		)->fetchAll();

		$outcomes = [];
		foreach ($rows as $row) {
			$outcome = Outcome::of(ActionStatus::from((string) $row->status), (bool) $row->rejected);
			$outcomes[(string) $row->type] ??= array_fill_keys(array_map(fn(Outcome $item): string => $item->value, Outcome::cases()), 0);
			$outcomes[(string) $row->type][$outcome->value] += (int) $row->cnt;
		}

		return $outcomes;
	}


	/**
	 * Reasons recorded on interventions (what stopped them or returned them to a human).
	 *
	 * @return array<string, int> reason code (decision.reason.<code>) => interventions, most frequent first
	 */
	public function reasons(Timeline $timeline, Scope $scope, ?ActionType $type = null): array
	{
		return $this->database->query(
			'SELECT r.reason, COUNT(*) AS cnt
			FROM moderation_actions a ' . Scope::ActionAccount . '
			CROSS JOIN JSON_TABLE(a.reasons_json, \'$[*]\' COLUMNS (reason VARCHAR(64) PATH \'$\')) r
			WHERE a.created_at >= ? AND a.created_at < ? AND a.reasons_json IS NOT NULL AND ?and
			GROUP BY r.reason ORDER BY cnt DESC, r.reason',
			$timeline->since,
			$timeline->until,
			$scope->accounts('s') + ($type === null ? [] : [
				'a.type' => $type->value,
			]),
		)->fetchPairs('reason', 'cnt');
	}


	/**
	 * @return array<string, int> App\Meta\Platform value => received events
	 */
	public function platforms(Timeline $timeline, Scope $scope): array
	{
		return $this->database->query(
			'SELECT e.platform, COUNT(*) AS cnt FROM social_events e
			WHERE e.received_at >= ? AND e.received_at < ? AND ?and
			GROUP BY e.platform ORDER BY e.platform',
			$timeline->since,
			$timeline->until,
			$scope->events('e'),
		)->fetchPairs('platform', 'cnt');
	}


	/**
	 * Token usage of the classifier, the moderation endpoint excluded, and of reply drafts, per model.
	 *
	 * @return list<array{model: string, calls: int, input: int, output: int}>
	 */
	public function tokens(Timeline $timeline, Scope $scope): array
	{
		$rows = $this->database->query(
			'SELECT model, COUNT(*) AS calls, SUM(input_tokens) AS input, SUM(output_tokens) AS output FROM (
				SELECT d.model, d.input_tokens, d.output_tokens FROM moderation_decisions d
				JOIN social_events e ON e.id = d.event_id
				WHERE d.created_at >= ? AND d.created_at < ? AND d.input_tokens IS NOT NULL AND ?and
				UNION ALL
				SELECT a.model, a.input_tokens, a.output_tokens FROM moderation_actions a ' . Scope::ActionAccount . '
				WHERE a.created_at >= ? AND a.created_at < ? AND a.input_tokens IS NOT NULL AND ?and
			) usage_rows
			GROUP BY model
			ORDER BY model',
			$timeline->since,
			$timeline->until,
			$scope->events('e'),
			$timeline->since,
			$timeline->until,
			$scope->accounts('s'),
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
	public function approvals(Scope $scope): array
	{
		$row = $this->database->query(
			'SELECT COUNT(*) AS cnt, MIN(a.created_at) AS oldest FROM moderation_actions a ' . Scope::ActionAccount . '
			WHERE a.status = ? AND ?and',
			ActionStatus::Pending->value,
			$scope->accounts('s'),
		)->fetch();

		return [
			'count' => (int) $row?->cnt,
			'oldest' => $row?->oldest instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($row->oldest) : null,
		];
	}
}
