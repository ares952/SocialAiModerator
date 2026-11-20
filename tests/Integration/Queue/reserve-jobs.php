<?php

declare(strict_types=1);

// Child process of JobQueue.phpt: reserves and finishes jobs until the queue is empty and prints
// the reserved job IDs as JSON. Uses the test database prepared by the parent.

use App\Queue\JobQueue;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../vendor/autoload.php';

$worker = $argv[1] ?? throw new LogicException('Usage: reserve-jobs.php <worker>');
$queue = new JobQueue(
	TestDatabase::connect(),
	maxAttempts: 3,
	backoffSeconds: 10,
	backoffCapSeconds: 25,
	leaseSeconds: 600,
);

$ids = [];
while (($job = $queue->reserve($worker)) !== null) {
	usleep(random_int(0, 500)); // let the other process interleave
	if (!$queue->finish($job)) {
		throw new LogicException("Job $job->id lost its reservation.");
	}
	$ids[] = $job->id;
}

echo json_encode($ids, JSON_THROW_ON_ERROR);
