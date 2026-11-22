<?php

declare(strict_types=1);

namespace App\Ingest;

use App\Meta\EventType;
use App\Queue\JobType;
use Nette\Database\Explorer;
use Nette\Database\Row;


/**
 * Read model of incoming events for the administration. Never decrypts payloads: lists show
 * metadata only, no customer content.
 */
final class EventOverview
{
	public function __construct(
		private readonly Explorer $database,
	) {
	}


	/**
	 * @return array<string, int> status => events received in the last 24 hours
	 */
	public function recentCounts(): array
	{
		return $this->database->query(
			'SELECT status, COUNT(*) AS cnt FROM social_events
			WHERE received_at > NOW() - INTERVAL 1 DAY
			GROUP BY status',
		)->fetchPairs('status', 'cnt');
	}


	public function count(?EventStatus $status, ?EventType $type, ?int $accountId): int
	{
		return (int) $this->database->query(
			'SELECT COUNT(*) FROM social_events e WHERE ?and',
			self::filter($status, $type, $accountId),
		)->fetchField();
	}


	/**
	 * Newest first, with the account name and the state of the processing job.
	 *
	 * @return list<Row>
	 */
	public function page(?EventStatus $status, ?EventType $type, ?int $accountId, int $limit, int $offset): array
	{
		return $this->database->query(
			'SELECT e.id, e.received_at, e.occurred_at, e.platform, e.account_external_id, a.name AS account_name,
				e.topic, e.event_type, e.external_id, e.status, e.attempts, e.last_error,
				j.status AS job_status, j.last_error AS job_error,
				(SELECT d.action FROM moderation_decisions d WHERE d.event_id = e.id ORDER BY d.id DESC LIMIT 1) AS decision_action
			FROM social_events e
			LEFT JOIN social_accounts a ON a.id = e.account_id
			LEFT JOIN queue_jobs j ON j.type = ? AND j.subject_id = e.id
			WHERE ?and
			ORDER BY e.id DESC
			LIMIT ? OFFSET ?',
			JobType::ProcessEvent->value,
			self::filter($status, $type, $accountId),
			$limit,
			$offset,
		)->fetchAll();
	}


	/**
	 * Metadata of one event with the account name and its review queue state; no customer content.
	 */
	public function find(int $id): ?Row
	{
		return $this->database->query(
			'SELECT e.id, e.received_at, e.occurred_at, e.platform, e.account_id, e.account_external_id,
				a.name AS account_name, e.topic, e.event_type, e.external_id, e.status, e.attempts, e.last_error,
				e.resolved_at, r.name AS resolved_by_name, e.assignee_id, o.name AS assignee_name
			FROM social_events e
			LEFT JOIN social_accounts a ON a.id = e.account_id
			LEFT JOIN operators r ON r.id = e.resolved_by
			LEFT JOIN operators o ON o.id = e.assignee_id
			WHERE e.id = ?',
			$id,
		)->fetch();
	}


	/**
	 * Worker jobs of the event (processing, AI decision), oldest first.
	 *
	 * @return list<Row>
	 */
	public function jobs(int $eventId): array
	{
		return $this->database->query(
			'SELECT type, status, attempts, last_error, available_at, updated_at FROM queue_jobs
			WHERE type IN (?) AND subject_id = ?
			ORDER BY id',
			[
				JobType::ProcessEvent->value,
				JobType::DecideEvent->value,
			],
			$eventId,
		)->fetchAll();
	}


	/**
	 * @return array<string, int|string>
	 */
	private static function filter(?EventStatus $status, ?EventType $type, ?int $accountId): array
	{
		$filter = [];
		if ($status !== null) {
			$filter['e.status'] = $status->value;
		}

		if ($type !== null) {
			$filter['e.event_type'] = $type->value;
		}

		if ($accountId !== null) {
			$filter['e.account_id'] = $accountId;
		}

		return $filter;
	}
}
