<?php

declare(strict_types=1);

use App\Console\EventsNormalizeCommand;
use App\Audit\AuditLog;
use App\Console\WorkerCommand;
use App\Core\Correlation;
use App\Core\Encryptor;
use App\Ingest\EventProcessor;
use App\Ingest\EventStore;
use App\Ingest\WebhookBatch;
use App\Meta\EventNormalizer;
use App\Queue\JobQueue;
use App\Retention\Retention;
use Nette\Utils\Json;
use Symfony\Component\Console\Tester\CommandTester;
use Tester\Assert;
use Tests\Fakes\FakeMetaClient;
use Tests\Fakes\FakeOpenAiClient;
use Tests\Support\TestDatabase;
use Tests\Support\TestLog;
use Tests\Support\TestServices;

require __DIR__ . '/../../bootstrap.php';

// Stored event -> queue -> worker -> EventNormalizer (specification §3.3, §5.1): relevant events end
// in review with their metadata, events without anything to moderate as ignored; nothing is sent
// anywhere.

$database = TestDatabase::create();
$encryptor = new Encryptor(base64_encode(random_bytes(32)));
$queue = new JobQueue(
	$database,
	maxAttempts: 2,
	backoffSeconds: 10,
	backoffCapSeconds: 25,
	leaseSeconds: 600,
);
$database->query('INSERT INTO social_accounts ?', [
	'platform' => 'facebook',
	'external_id' => 'fb:1000000000000001',
	'name' => 'Test Page',
	'status' => 'active',
]);

$log = new TestLog;

$work = static function (Encryptor $encryptor) use ($database, $queue, $log): void {
	$tester = new CommandTester(new WorkerCommand(
		heartbeatFile: TEMP_DIR . '/worker.heartbeat',
		idleSeconds: 1,
		retentionSeconds: 3600,
		queue: $queue,
		events: TestServices::eventProcessor($database, $encryptor, $queue),
		decider: TestServices::eventDecider($database, $encryptor, $queue, new FakeOpenAiClient),
		evaluation: TestServices::evaluationRunner($database, $encryptor, new FakeOpenAiClient),
		dispatcher: TestServices::dispatcher($database, $encryptor, new FakeMetaClient, $log->logger),
		retention: new Retention($database, new AuditLog($database)),
		correlation: new Correlation,
		logger: $log->logger,
	));
	Assert::same(0, $tester->execute([
		'--until-empty' => true,
	]));
};

TestServices::eventStore($database, $encryptor, $queue)->save(WebhookBatch::parse(
	'{"object":"page","entry":[{"id":"1000000000000001","changes":[{"field":"feed","value":{"item":"comment","verb":"add","comment_id":"c1","created_time":1763640000}}]}]}',
));


test('worker normalizes a stored event, moves it to review and finishes the job', function () use ($database, $encryptor, $work, $log): void {
	$work($encryptor);

	$event = $database->query('SELECT status, attempts, event_type, external_id, occurred_at, last_error FROM social_events')->fetch() ?? throw new LogicException;
	Assert::same('review', $event->status);
	Assert::same(1, $event->attempts);
	Assert::same('comment.created', $event->event_type);
	Assert::same('fb:c1', $event->external_id);
	Assert::same('2025-11-20 12:00:00', $event->occurred_at?->format('Y-m-d H:i:s'));
	Assert::null($event->last_error);
	Assert::same('done', $database->query('SELECT status FROM queue_jobs')->fetchField());
	Assert::same(['info: Worker started', 'info: Worker stopped'], $log->messages());
	Assert::true(is_file(TEMP_DIR . '/worker.heartbeat'));
});


test('an event without anything to moderate is ignored, an unsupported one goes to review with the reason', function () use ($database, $encryptor, $queue, $work): void {
	TestServices::eventStore($database, $encryptor, $queue)->save(WebhookBatch::parse(Json::encode([
		'object' => 'page',
		'entry' => [
			[
				'id' => '1000000000000001',
				'changes' => [
					['field' => 'feed', 'value' => ['item' => 'reaction', 'verb' => 'add', 'created_time' => 1763640100]],
					['field' => 'feed', 'value' => ['item' => 'comment', 'verb' => 'block', 'comment_id' => 'c2']],
				],
			],
		],
	])));
	$work($encryptor);

	$events = $database->query('SELECT status, event_type, last_error FROM social_events WHERE id > 1 ORDER BY id')->fetchAll();
	Assert::same(['ignored', 'other', null], [$events[0]->status, $events[0]->event_type, $events[0]->last_error]);
	Assert::same(['review', 'unsupported', 'feed comment verb `block` is not supported'], [$events[1]->status, $events[1]->event_type, $events[1]->last_error]);
	$database->query('DELETE FROM queue_jobs WHERE subject_id > 1');
	$database->query('DELETE FROM social_events WHERE id > 1');
});


test('events:normalize fills events without a type and keeps their status', function () use ($database, $encryptor, $queue): void {
	$database->query("UPDATE social_events SET event_type = NULL, external_id = NULL, occurred_at = NULL, status = 'review'");
	$command = new CommandTester(new EventsNormalizeCommand($database, $encryptor, new EventNormalizer, TestServices::eventProcessor($database, $encryptor, $queue)));

	Assert::same(0, $command->execute([]));
	Assert::same("comment.created: 1\n1 events normalized\n", $command->getDisplay());
	$event = $database->query('SELECT status, event_type, external_id FROM social_events')->fetch() ?? throw new LogicException;
	Assert::same(['review', 'comment.created', 'fb:c1'], [$event->status, $event->event_type, $event->external_id]);

	Assert::same(0, $command->execute([]));
	Assert::same("0 events normalized\n", $command->getDisplay(), 'Already normalized events are skipped.');

	Assert::same(0, $command->execute(['--all' => true]));
	Assert::same("comment.created: 1\n1 events normalized\n", $command->getDisplay());
});


test('an event that cannot be decrypted fails the job after all attempts', function () use ($database, $work, $log): void {
	$log->clear();
	$database->query("UPDATE social_events SET status = 'received'");
	$database->query("UPDATE queue_jobs SET status = 'pending', attempts = 0");
	$wrongKey = new Encryptor(base64_encode(random_bytes(32)));

	$work($wrongKey);
	Assert::same('pending', $database->query('SELECT status FROM queue_jobs')->fetchField());
	Assert::same(['info: Worker started', 'warning: Job failed, will retry', 'info: Worker stopped'], $log->messages());

	$database->query('UPDATE queue_jobs SET available_at = NOW(3)');
	$work($wrongKey);
	$job = $database->query('SELECT status, last_error FROM queue_jobs')->fetch() ?? throw new LogicException;
	Assert::same('failed', $job->status);
	Assert::match('App\Core\DecryptionFailed: Cannot decrypt social_events.payload%a%', $job->last_error);
	Assert::same('received', $database->query('SELECT status FROM social_events')->fetchField());
	Assert::contains('error: Job failed, no attempts left', $log->messages());
});
