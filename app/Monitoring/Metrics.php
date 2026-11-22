<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Meta\EventType;
use App\Moderation\Action;
use App\Outbox\ActionStatus;
use App\Outbox\ActionType;
use App\Queue\JobStatus;
use Nette\Database\Explorer;


/**
 * Numbers of the dashboard over the last hours (specification §4.3, §8.4): received events, AI
 * decisions with the time to classification, escalations to a human, hides and replies proposed
 * and sent, rejected and blocked actions, failures, and token usage per model.
 */
final class Metrics
{
	public function __construct(
		private readonly Explorer $database,
	) {
	}


	/**
	 * @return array<string, int|float|null> metric => value
	 */
	public function summary(int $hours): array
	{
		$events = $this->database->query(
			'SELECT COUNT(*) AS received, SUM(event_type LIKE ?) AS comments, SUM(event_type = ?) AS messages
			FROM social_events WHERE received_at >= NOW() - INTERVAL ? HOUR',
			'comment.%',
			EventType::MessageReceived->value,
			$hours,
		)->fetch();
		$decisions = $this->database->query(
			'SELECT COUNT(*) AS decided, SUM(d.action IN (?)) AS escalated, SUM(d.category IS NULL) AS unclassified,
				AVG(TIMESTAMPDIFF(SECOND, e.received_at, d.created_at)) AS seconds
			FROM moderation_decisions d
			JOIN social_events e ON e.id = d.event_id
			WHERE d.created_at >= NOW(3) - INTERVAL ? HOUR',
			[
				Action::Handoff->value,
				Action::Review->value,
			],
			$hours,
		)->fetch();
		$actions = $this->database->query(
			'SELECT SUM(type = ?) AS hides_proposed, SUM(type = ? AND status = ?) AS hides_sent,
				SUM(type = ? AND text_encrypted IS NOT NULL) AS replies_drafted, SUM(type = ? AND status = ?) AS replies_sent,
				SUM(status = ? AND JSON_CONTAINS(COALESCE(reasons_json, JSON_ARRAY()), ?)) AS rejected,
				SUM(status = ? AND NOT JSON_CONTAINS(COALESCE(reasons_json, JSON_ARRAY()), ?)) AS blocked,
				SUM(status IN (?)) AS failed
			FROM moderation_actions WHERE created_at >= NOW(3) - INTERVAL ? HOUR',
			ActionType::Hide->value,
			ActionType::Hide->value,
			ActionStatus::Sent->value,
			ActionType::Reply->value,
			ActionType::Reply->value,
			ActionStatus::Sent->value,
			ActionStatus::Blocked->value,
			'"rejected"',
			ActionStatus::Blocked->value,
			'"rejected"',
			[
				ActionStatus::Failed->value,
				ActionStatus::Unknown->value,
			],
			$hours,
		)->fetch();
		$jobs = $this->database->query(
			'SELECT COUNT(*) FROM queue_jobs WHERE status = ? AND updated_at >= NOW(3) - INTERVAL ? HOUR',
			JobStatus::Failed->value,
			$hours,
		)->fetchField();

		$int = static fn(mixed $value): int => (int) $value;

		return [
			'events' => $int($events?->received),
			'comments' => $int($events?->comments),
			'messages' => $int($events?->messages),
			'decisions' => $int($decisions?->decided),
			'escalations' => $int($decisions?->escalated),
			'unclassified' => $int($decisions?->unclassified),
			'classification_seconds' => $decisions?->seconds === null ? null : round((float) $decisions->seconds, 1),
			'hides_proposed' => $int($actions?->hides_proposed),
			'hides_sent' => $int($actions?->hides_sent),
			'replies_drafted' => $int($actions?->replies_drafted),
			'replies_sent' => $int($actions?->replies_sent),
			'rejected' => $int($actions?->rejected),
			'blocked' => $int($actions?->blocked),
			'failed_actions' => $int($actions?->failed),
			'failed_jobs' => $int($jobs),
		];
	}


	/**
	 * Token usage of the classifier, the moderation endpoint excluded, and of reply drafts, per model.
	 *
	 * @return list<array{model: string, calls: int, input: int, output: int}>
	 */
	public function tokens(int $hours): array
	{
		$rows = $this->database->query(
			'SELECT model, COUNT(*) AS calls, SUM(input_tokens) AS input, SUM(output_tokens) AS output FROM (
				SELECT model, input_tokens, output_tokens FROM moderation_decisions
				WHERE created_at >= NOW(3) - INTERVAL ? HOUR AND input_tokens IS NOT NULL
				UNION ALL
				SELECT model, input_tokens, output_tokens FROM moderation_actions
				WHERE created_at >= NOW(3) - INTERVAL ? HOUR AND input_tokens IS NOT NULL
			) usage_rows
			GROUP BY model
			ORDER BY model',
			$hours,
			$hours,
		)->fetchAll();

		return array_map(fn($row) => [
			'model' => (string) $row->model,
			'calls' => (int) $row->calls,
			'input' => (int) $row->input,
			'output' => (int) $row->output,
		], $rows);
	}
}
