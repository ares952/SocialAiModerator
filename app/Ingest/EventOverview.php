<?php

declare(strict_types=1);

namespace App\Ingest;

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


	public function count(?EventStatus $status): int
	{
		return (int) $this->database->query(
			'SELECT COUNT(*) FROM social_events WHERE ?and',
			$status === null ? [] : ['status' => $status->value],
		)->fetchField();
	}


	/**
	 * Newest first, with the account name and the state of the processing job.
	 *
	 * @return list<Row>
	 */
	public function page(?EventStatus $status, int $limit, int $offset): array
	{
		return $this->database->query(
			'SELECT e.id, e.received_at, e.platform, e.account_external_id, a.name AS account_name,
				e.topic, e.status, e.attempts, j.status AS job_status, j.last_error AS job_error
			FROM social_events e
			LEFT JOIN social_accounts a ON a.id = e.account_id
			LEFT JOIN queue_jobs j ON j.type = ? AND j.subject_id = e.id
			WHERE ?and
			ORDER BY e.id DESC
			LIMIT ? OFFSET ?',
			JobType::ProcessEvent->value,
			$status === null ? [] : ['e.status' => $status->value],
			$limit,
			$offset,
		)->fetchAll();
	}
}
