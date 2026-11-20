<?php

declare(strict_types=1);

namespace App\Queue;

use Nette\Database\Explorer;


/**
 * Circuit breaker in the database, so all workers share it (specification §8.1). After
 * `failureThreshold` consecutive transient failures it stays open for `openSeconds`; the first call
 * after that is a trial: success closes it, another failure opens it again.
 */
final class CircuitBreaker
{
	public function __construct(
		private readonly Explorer $database,
		private readonly int $failureThreshold,
		private readonly int $openSeconds,
	) {
	}


	/**
	 * Seconds until calls may be tried again, or null when the breaker is closed.
	 */
	public function retryAfter(string $name): ?int
	{
		$seconds = $this->database->query(
			'SELECT CEIL(TIMESTAMPDIFF(MICROSECOND, NOW(3), opened_until) / 1000000)
			FROM circuit_breakers WHERE name = ? AND opened_until > NOW(3)',
			$name,
		)->fetchField();

		return $seconds === null ? null : max(1, (int) $seconds);
	}


	public function recordFailure(string $name): void
	{
		// opened_until first: it must see the failure count before this increment.
		$this->database->query(
			'INSERT INTO circuit_breakers (name, failures, opened_until) VALUES (?, 1, IF(1 >= ?, NOW(3) + INTERVAL ? SECOND, NULL))
			ON DUPLICATE KEY UPDATE
				opened_until = IF(failures + 1 >= ?, NOW(3) + INTERVAL ? SECOND, opened_until),
				failures = failures + 1',
			$name,
			$this->failureThreshold,
			$this->openSeconds,
			$this->failureThreshold,
			$this->openSeconds,
		);
	}


	public function recordSuccess(string $name): void
	{
		$this->database->query(
			'UPDATE circuit_breakers SET failures = 0, opened_until = NULL WHERE name = ? AND failures > 0',
			$name,
		);
	}
}
