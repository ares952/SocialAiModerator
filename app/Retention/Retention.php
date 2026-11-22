<?php

declare(strict_types=1);

namespace App\Retention;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Queue\JobType;
use Nette\Database\Explorer;


/**
 * Retention periods editable by an admin, and the purge that applies them: events older than
 * the period of their kind are deleted with their AI decisions and worker jobs, the audit log
 * likewise. The projected state follows the same period: comments not changed within it (and
 * posts left without comments), messages older than it (and conversations left without
 * messages). Data without a period is kept. Deletion runs in small batches, so it never blocks
 * the ingest for long.
 */
final class Retention
{
	public const MaxDays = 3650;
	private const BatchSize = 500;

	/** event_type values per kind of event data; other_events is everything else. */
	private const CommentTypes = 'comment.%';
	private const MessageTypes = [
		'message.received',
		'message.echo',
		'message.deleted',
		'postback',
	];


	public function __construct(
		private readonly Explorer $database,
		private readonly AuditLog $audit,
	) {
	}


	/**
	 * @return array<string, int|null> RetentionData value => days, null = keep
	 */
	public function periods(): array
	{
		$stored = $this->database->query('SELECT data, days FROM retention_periods')->fetchPairs('data', 'days');
		$periods = [];
		foreach (RetentionData::cases() as $data) {
			$days = $stored[$data->value] ?? null;
			$periods[$data->value] = $days === null ? null : (int) $days;
		}

		return $periods;
	}


	/**
	 * @param array<string, int|null> $periods RetentionData value => days, null = keep
	 */
	public function save(array $periods, int $operatorId): void
	{
		$details = [];
		$this->database->transaction(function () use ($periods, $operatorId, &$details): void {
			foreach (RetentionData::cases() as $data) {
				$days = $periods[$data->value] ?? null;
				if ($days !== null && ($days < 1 || $days > self::MaxDays)) {
					throw new \InvalidArgumentException("Retention of $data->value must be 1 to " . self::MaxDays . ' days.');
				}

				$this->database->query(
					'INSERT INTO retention_periods ? ON DUPLICATE KEY UPDATE ?',
					[
						'data' => $data->value,
						'days' => $days,
						'updated_by' => $operatorId,
					],
					[
						'days' => $days,
						'updated_by' => $operatorId,
					],
				);
				$details[] = "$data->value=" . ($days ?? 'keep');
			}
			$this->audit->write($operatorId, AuditAction::RetentionUpdate, 'retention', null, implode(', ', $details));
		});
	}


	/**
	 * Deletes everything older than its period.
	 *
	 * @return array<string, int> RetentionData value => deleted events or audit rows (only kinds
	 *         with a period); the projected comments and messages are deleted along
	 */
	public function purge(): array
	{
		$deleted = [];
		foreach ($this->periods() as $data => $days) {
			if ($days === null) {
				continue;
			}

			$data = RetentionData::from($data);
			$deleted[$data->value] = $data === RetentionData::Audit
				? $this->purgeRows('DELETE FROM admin_audit WHERE created_at < NOW() - INTERVAL ? DAY ORDER BY id LIMIT ?', $days)
				: $this->purgeEvents($data, $days);
			match ($data) {
				RetentionData::Comments => $this->purgeComments($days),
				RetentionData::Messages => $this->purgeMessages($days),
				default => null,
			};
		}

		return $deleted;
	}


	private function purgeEvents(RetentionData $data, int $days): int
	{
		[$condition, $params] = match ($data) {
			RetentionData::Comments => ['event_type LIKE ?', [self::CommentTypes]],
			RetentionData::Messages => ['event_type IN (?)', [self::MessageTypes]],
			default => [
				'(event_type IS NULL OR (event_type NOT LIKE ? AND event_type NOT IN (?)))',
				[self::CommentTypes, self::MessageTypes],
			],
		};

		$deleted = 0;
		do {
			$ids = $this->database->query(
				'SELECT id FROM social_events WHERE received_at < NOW() - INTERVAL ? DAY AND ' . $condition . ' ORDER BY id LIMIT ?',
				$days,
				...[...$params, self::BatchSize],
			)->fetchPairs(null, 'id');
			if ($ids === []) {
				break;
			}

			$this->database->transaction(function () use ($ids): void {
				$this->database->query(
					'DELETE FROM queue_jobs WHERE type IN (?) AND subject_id IN (?)',
					[
						JobType::ProcessEvent->value,
						JobType::DecideEvent->value,
					],
					$ids,
				);
				$this->database->query('DELETE FROM moderation_decisions WHERE event_id IN (?)', $ids);
				$this->database->query('DELETE FROM social_events WHERE id IN (?)', $ids);
			});
			$deleted += count($ids);
		} while (count($ids) === self::BatchSize);

		return $deleted;
	}


	private function purgeComments(int $days): void
	{
		$this->purgeRows('DELETE FROM social_comments WHERE updated_at < NOW() - INTERVAL ? DAY ORDER BY id LIMIT ?', $days);
		$this->purgeRows(
			'DELETE FROM social_posts
			WHERE created_at < NOW() - INTERVAL ? DAY
				AND NOT EXISTS (SELECT 1 FROM social_comments c WHERE c.post_id = social_posts.id)
			ORDER BY id LIMIT ?',
			$days,
		);
	}


	private function purgeMessages(int $days): void
	{
		$this->purgeRows('DELETE FROM social_messages WHERE created_at < NOW() - INTERVAL ? DAY ORDER BY id LIMIT ?', $days);
		$this->purgeRows(
			'DELETE FROM social_conversations
			WHERE created_at < NOW() - INTERVAL ? DAY
				AND NOT EXISTS (SELECT 1 FROM social_messages m WHERE m.conversation_id = social_conversations.id)
			ORDER BY id LIMIT ?',
			$days,
		);
	}


	/**
	 * Runs a DELETE with the parameters (days, batch size) until a batch is not full.
	 *
	 * @param literal-string $sql
	 */
	private function purgeRows(string $sql, int $days): int
	{
		$deleted = 0;
		do {
			$count = $this->database->query($sql, $days, self::BatchSize)->getRowCount() ?? 0;
			$deleted += $count;
		} while ($count === self::BatchSize);

		return $deleted;
	}
}
