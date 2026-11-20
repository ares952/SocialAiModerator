<?php

declare(strict_types=1);

use App\Ai\GuardedOpenAiClient;
use App\Ai\OpenAiFailure;
use App\Queue\CircuitBreaker;
use App\Queue\JobQueue;
use App\Queue\JobType;
use App\Queue\Postponed;
use Tester\Assert;
use Tests\Fakes\FakeOpenAiClient;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

// Specification §8.1: repeated AI failures open a breaker shared by all workers; jobs wait
// without losing attempts.

$database = TestDatabase::create();
$breaker = new CircuitBreaker($database, failureThreshold: 3, openSeconds: 60);
$close = static fn() => $database->query("UPDATE circuit_breakers SET opened_until = NOW(3) - INTERVAL 1 SECOND");


test('the breaker opens after consecutive failures and a success closes it', function () use ($breaker, $close): void {
	Assert::null($breaker->retryAfter('openai'));
	$breaker->recordFailure('openai');
	$breaker->recordFailure('openai');
	Assert::null($breaker->retryAfter('openai'));

	$breaker->recordFailure('openai');
	$retryAfter = $breaker->retryAfter('openai');
	Assert::true($retryAfter !== null && $retryAfter > 55 && $retryAfter <= 60, "retry after $retryAfter");
	Assert::null($breaker->retryAfter('meta'));

	// Trial after the open period: another failure opens it at once.
	$close();
	Assert::null($breaker->retryAfter('openai'));
	$breaker->recordFailure('openai');
	Assert::notNull($breaker->retryAfter('openai'));

	$close();
	$breaker->recordSuccess('openai');
	$breaker->recordFailure('openai');
	Assert::null($breaker->retryAfter('openai'));
});


test('the guarded client counts only transient failures and sends nothing while open', function () use ($breaker, $database): void {
	$database->query('DELETE FROM circuit_breakers');
	$fake = new FakeOpenAiClient;
	$client = new GuardedOpenAiClient($fake, $breaker);

	$fake->queue(new OpenAiFailure('HTTP 400', transient: false));
	Assert::exception(fn() => $client->structured('m', 'i', 'x', 's', []), OpenAiFailure::class);
	Assert::null($database->query("SELECT failures FROM circuit_breakers WHERE name = 'openai'")->fetchField());

	for ($i = 0; $i < 3; $i++) {
		$fake->queue(new OpenAiFailure('HTTP 503', transient: true));
		Assert::exception(fn() => $client->structured('m', 'i', 'x', 's', []), OpenAiFailure::class);
	}
	$sent = count($fake->requests);
	Assert::exception(fn() => $client->moderate('m', 'x'), Postponed::class);
	Assert::exception(fn() => $client->structured('m', 'i', 'x', 's', []), Postponed::class);
	Assert::same($sent, count($fake->requests));
});


test('a postponed job keeps its attempts', function () use ($database): void {
	$queue = new JobQueue($database, maxAttempts: 2, backoffSeconds: 1, backoffCapSeconds: 1, leaseSeconds: 60);
	$queue->push(JobType::EvaluateSample, 1);
	$job = $queue->reserve('worker') ?? throw new LogicException;
	$queue->postpone($job, 30);

	$row = $database->query('SELECT status, attempts, available_at > NOW(3) + INTERVAL 25 SECOND AS later FROM queue_jobs WHERE id = ?', $job->id)->fetch() ?? throw new LogicException;
	Assert::same('pending', $row->status);
	Assert::same(0, $row->attempts);
	Assert::same(1, $row->later);
});
