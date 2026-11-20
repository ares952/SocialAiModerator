<?php

declare(strict_types=1);

namespace App\Queue;


/**
 * Thrown by a job handler that cannot run now through no fault of the job (e.g. an open circuit
 * breaker). The worker puts the job back without counting the attempt.
 */
final class Postponed extends \RuntimeException
{
	public function __construct(
		string $message,
		public readonly int $retryAfterSeconds,
	) {
		parent::__construct($message);
	}
}
