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


	public function count(?EventStatus $status, ?EventType $type): int
	{
		return (int) $this->database->query(
			'SELECT COUNT(*) FROM social_events e WHERE ?and',
			self::filter($status, $type),
		)->fetchField();
	}


	/**
	 * Newest first, with the account name and the state of the processing job.
	 *
	 * @return list<Row>
	 */
	public function page(?EventStatus $status, ?EventType $type, int $limit, int $offset): array
	{
		return $this->database->query(
			'SELECT e.id, e.received_at, e.occurred_at, e.platform, e.account_external_id, a.name AS account_name,
				e.topic, e.event_type, e.external_id, e.status, e.attempts, e.last_error,
				j.status AS job_status, j.last_error AS job_error
			FROM social_events e
			LEFT JOIN social_accounts a ON a.id = e.account_id
			LEFT JOIN queue_jobs j ON j.type = ? AND j.subject_id = e.id
			WHERE ?and
			ORDER BY e.id DESC
			LIMIT ? OFFSET ?',
			JobType::ProcessEvent->value,
			self::filter($status, $type),
			$limit,
			$offset,
		)->fetchAll();
	}


	/**
	 * @return array<string, string>
	 */
	private static function filter(?EventStatus $status, ?EventType $type): array
	{
		$filter = [];
		if ($status !== null) {
			$filter['e.status'] = $status->value;
		}

		if ($type !== null) {
			$filter['e.event_type'] = $type->value;
		}

		return $filter;
	}
}
