<?php

declare(strict_types=1);

namespace App\Queue;


/**
 * A reserved job. `attempts` includes the current attempt; `worker` holds the reservation.
 */
final readonly class Job
{
	public function __construct(
		public int $id,
		public JobType $type,
		public int $subjectId,
		public int $attempts,
		public string $worker,
	) {
	}
}
