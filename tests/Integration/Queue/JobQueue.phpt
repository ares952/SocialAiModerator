<?php

declare(strict_types=1);

use App\Queue\Job;
use App\Queue\JobQueue;
use App\Queue\JobStatus;
use App\Queue\JobType;
use Nette\Database\Row;
use Nette\Utils\Json;
use Tester\Assert;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';


$database = TestDatabase::create();
$queue = new JobQueue(
	$database,
	maxAttempts: 3,
	backoffSeconds: 10,
	backoffCapSeconds: 25,
	leaseSeconds: 600,
);

$reserve = static fn(string $worker): Job => $queue->reserve($worker)
	?? throw new LogicException('No job was reserved.');

$row = static fn(int $id): Row => $database->query(
	'SELECT status, attempts, reserved_by, last_error,
		TIMESTAMPDIFF(MICROSECOND, NOW(3), available_at) AS delay
	FROM queue_jobs WHERE id = ?',
	$id,
)->fetch() ?? throw new LogicException("Job $id does not exist.");

// Makes all waiting jobs available now.
$fastForward = static fn() => $database->query('UPDATE queue_jobs SET available_at = NOW(3)');


test('reserved job is not reserved again and finishes', function () use ($queue, $reserve, $row): void {
	$queue->push(JobType::ProcessEvent, 42);

	$job = $reserve('worker-a');
	Assert::same(JobType::ProcessEvent, $job->type);
	Assert::same(42, $job->subjectId);
	Assert::same(1, $job->attempts);
	Assert::null($queue->reserve('worker-b'));

	Assert::true($queue->finish($job));
	Assert::same('done', $row($job->id)->status);
	Assert::null($queue->reserve('worker-b'));
});


test('failure is retried with growing backoff, then the job fails', function () use ($queue, $reserve, $row, $fastForward): void {
	$queue->push(JobType::ProcessEvent, 43);

	$first = $reserve('worker-a');
	Assert::same(JobStatus::Pending, $queue->fail($first, 'RuntimeException: first'));
	Assert::same('RuntimeException: first', $row($first->id)->last_error);
	// attempt 1: 10 s, half of it random
	$delay = $row($first->id)->delay;
	Assert::true($delay >= 4_900_000 && $delay <= 10_000_000, "delay $delay");
	Assert::null($queue->reserve('worker-a'));

	$fastForward();
	$second = $reserve('worker-b');
	Assert::same(2, $second->attempts);
	Assert::same(JobStatus::Pending, $queue->fail($second, 'RuntimeException: second'));
	// attempt 2: 20 s
	$delay = $row($second->id)->delay;
	Assert::true($delay >= 9_900_000 && $delay <= 20_000_000, "delay $delay");

	$fastForward();
	$third = $reserve('worker-a');
	Assert::same(JobStatus::Failed, $queue->fail($third, 'RuntimeException: third'));
	Assert::same('failed', $row($third->id)->status);
	$fastForward();
	Assert::null($queue->reserve('worker-a'));
});


test('expired reservation is taken over and the old worker cannot finish it', function () use ($database, $queue, $reserve, $row): void {
	$queue->push(JobType::ProcessEvent, 44);
	$stale = $reserve('worker-a');
	$database->query('UPDATE queue_jobs SET reserved_until = NOW(3) - INTERVAL 1 SECOND WHERE id = ?', $stale->id);

	$takeover = $reserve('worker-b');
	Assert::same($stale->id, $takeover->id);
	Assert::same(2, $takeover->attempts);
	Assert::false($queue->finish($stale));
	Assert::true($queue->finish($takeover));
	Assert::same('worker-b', $row($takeover->id)->reserved_by);
});


test('expired reservation after the last attempt fails the job', function () use ($database, $queue, $reserve, $row): void {
	$queue->push(JobType::ProcessEvent, 45);
	$job = $reserve('worker-a');
	$database->query(
		'UPDATE queue_jobs SET attempts = 3, reserved_until = NOW(3) - INTERVAL 1 SECOND WHERE id = ?',
		$job->id,
	);

	Assert::null($queue->reserve('worker-b'));
	Assert::same('failed', $row($job->id)->status);
});


test('two worker processes never reserve the same job', function () use ($database, $queue): void {
	$database->query('DELETE FROM queue_jobs');
	for ($i = 1; $i <= 300; $i++) {
		$queue->push(JobType::ProcessEvent, $i);
	}

	$processes = [];
	foreach (['worker-a', 'worker-b'] as $worker) {
		$process = proc_open(
			[
				PHP_BINARY,
				__DIR__ . '/reserve-jobs.php',
				$worker,
			],
			[
				1 => ['pipe', 'w'],
				2 => ['pipe', 'w'],
			],
			$pipes,
		) ?: throw new LogicException('Cannot start a worker process.');
		$processes[] = [$process, $pipes];
	}

	/** @var list<list<int>> $reserved */
	$reserved = [];
	foreach ($processes as [$process, $pipes]) {
		$output = (string) stream_get_contents($pipes[1]);
		$errors = (string) stream_get_contents($pipes[2]);
		Assert::same(0, proc_close($process), $errors);
		$reserved[] = Json::decode($output, forceArrays: true);
	}

	[$a, $b] = $reserved;
	// Both took part; otherwise the test would prove nothing about parallel reservation.
	Assert::true($a !== [] && $b !== [], 'both workers reserved jobs');
	Assert::same([], array_intersect($a, $b));
	Assert::same(300, count($a) + count($b));
	Assert::same(300, $database->query("SELECT COUNT(*) FROM queue_jobs WHERE status = 'done' AND attempts = 1")->fetchField());
});
