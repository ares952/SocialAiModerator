<?php

declare(strict_types=1);

namespace App\Queue;

use Nette\Database\Explorer;
use Nette\Database\SqlLiteral;
use Nette\Utils\Strings;


/**
 * DB job queue. All times come from the database clock, so several workers agree on them.
 *
 * - reserve(): one transaction with FOR UPDATE SKIP LOCKED; no job runs twice in parallel as
 *   long as a job finishes within the lease (leaseSeconds must exceed the longest job).
 * - A reservation past its lease (killed worker) counts as a failed attempt.
 * - fail(): retry with exponential backoff and jitter until maxAttempts, then `failed`.
 */
final class JobQueue
{
	public function __construct(
		private readonly Explorer $database,
		private readonly int $maxAttempts,
		private readonly int $backoffSeconds,
		private readonly int $backoffCapSeconds,
		private readonly int $leaseSeconds,
	) {
	}


	public function push(JobType $type, int $subjectId): void
	{
		$this->database->query('INSERT INTO queue_jobs ?', [
			'type' => $type->value,
			'subject_id' => $subjectId,
			'status' => JobStatus::Pending->value,
			'available_at' => new SqlLiteral('NOW(3)'),
		]);
	}


	public function reserve(string $worker): ?Job
	{
		$this->expire();

		// Without gap locks, concurrent reservations only skip each other's rows (no deadlock).
		$this->database->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');

		return $this->database->transaction(function () use ($worker): ?Job {
			$row = $this->database->query(
				'SELECT id, type, subject_id, attempts FROM queue_jobs
				WHERE status = ? AND available_at <= NOW(3)
				ORDER BY available_at, id
				LIMIT 1
				FOR UPDATE SKIP LOCKED',
				JobStatus::Pending->value,
			)->fetch();
			if ($row === null) {
				return null;
			}

			$this->database->query(
				'UPDATE queue_jobs SET status = ?, attempts = attempts + 1, reserved_by = ?,
					reserved_until = NOW(3) + INTERVAL ? SECOND
				WHERE id = ?',
				JobStatus::Reserved->value,
				$worker,
				$this->leaseSeconds,
				$row->id,
			);

			return new Job(
				id: (int) $row->id,
				type: JobType::from($row->type),
				subjectId: (int) $row->subject_id,
				attempts: $row->attempts + 1,
				worker: $worker,
			);
		});
	}


	/**
	 * @return bool false when the reservation was lost (lease expired and another worker took over)
	 */
	public function finish(Job $job): bool
	{
		return $this->database->query(
			'UPDATE queue_jobs SET status = ?, reserved_until = NULL, last_error = NULL
			WHERE id = ? AND status = ? AND reserved_by = ?',
			JobStatus::Done->value,
			$job->id,
			JobStatus::Reserved->value,
			$job->worker,
		)->getRowCount() === 1;
	}


	/**
	 * @param string $error exception class and message, never customer content
	 * @return JobStatus Pending (retry later) or Failed (attempts exhausted)
	 */
	public function fail(Job $job, string $error): JobStatus
	{
		$status = $job->attempts < $this->maxAttempts ? JobStatus::Pending : JobStatus::Failed;
		$this->database->query(
			'UPDATE queue_jobs SET status = ?, available_at = NOW(3) + INTERVAL ? MICROSECOND,
				reserved_until = NULL, last_error = ?
			WHERE id = ? AND status = ? AND reserved_by = ?',
			$status->value,
			$status === JobStatus::Pending ? $this->backoff($job->attempts) : 0,
			Strings::truncate($error, 1000),
			$job->id,
			JobStatus::Reserved->value,
			$job->worker,
		);

		return $status;
	}


	/**
	 * Expired reservations are rare: find them with a non-locking read, then release each one by
	 * its primary key, so this never locks index ranges that other workers reserve from.
	 */
	/**
	 * Whether a failure of this attempt makes the job `failed` (no retry follows).
	 */
	public function isLastAttempt(Job $job): bool
	{
		return $job->attempts >= $this->maxAttempts;
	}


	/**
	 * @return array<string, int> status => number of jobs
	 */
	public function counts(): array
	{
		return $this->database->query('SELECT status, COUNT(*) AS cnt FROM queue_jobs GROUP BY status')
			->fetchPairs('status', 'cnt');
	}


	private function expire(): void
	{
		$expired = $this->database->query(
			'SELECT id FROM queue_jobs WHERE status = ? AND reserved_until < NOW(3)',
			JobStatus::Reserved->value,
		)->fetchPairs(null, 'id');

		foreach ($expired as $id) {
			$this->database->query(
				'UPDATE queue_jobs SET status = IF(attempts >= ?, ?, ?), available_at = NOW(3),
					reserved_until = NULL, last_error = ?
				WHERE id = ? AND status = ? AND reserved_until < NOW(3)',
				$this->maxAttempts,
				JobStatus::Failed->value,
				JobStatus::Pending->value,
				'Reservation expired before the job finished (worker stopped or job too slow).',
				$id,
				JobStatus::Reserved->value,
			);
		}
	}


	/**
	 * Exponential delay with "equal jitter": half fixed, half random, in microseconds.
	 */
	private function backoff(int $attempt): int
	{
		$delay = min($this->backoffCapSeconds, $this->backoffSeconds * 2 ** ($attempt - 1)) * 1_000_000;

		return intdiv($delay, 2) + random_int(0, intdiv($delay, 2));
	}
}
