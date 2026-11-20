<?php

declare(strict_types=1);

use App\Console\WorkerCommand;
use App\Core\Correlation;
use App\Core\Encryptor;
use App\Ingest\EventProcessor;
use App\Ingest\EventStore;
use App\Ingest\WebhookBatch;
use App\Queue\JobQueue;
use Symfony\Component\Console\Tester\CommandTester;
use Tester\Assert;
use Tests\Fakes\FakeOpenAiClient;
use Tests\Support\TestDatabase;
use Tests\Support\TestLog;
use Tests\Support\TestServices;

require __DIR__ . '/../../bootstrap.php';

// Stored event -> queue -> worker (specification §5.1: until the normalizer exists, every event
// ends in review; nothing is sent anywhere).

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
		queue: $queue,
		events: new EventProcessor($database, $encryptor),
		evaluation: TestServices::evaluationRunner($database, $encryptor, new FakeOpenAiClient),
		correlation: new Correlation,
		logger: $log->logger,
	));
	Assert::same(0, $tester->execute([
		'--until-empty' => true,
	]));
};

(new EventStore($database, $encryptor, $queue))->save(WebhookBatch::parse(
	'{"object":"page","entry":[{"id":"1000000000000001","changes":[{"field":"feed","value":{"verb":"add","comment_id":"c1"}}]}]}',
));


test('worker moves a stored event to review and finishes the job', function () use ($database, $encryptor, $work, $log): void {
	$work($encryptor);

	$event = $database->query('SELECT status, attempts FROM social_events')->fetch() ?? throw new LogicException;
	Assert::same('review', $event->status);
	Assert::same(1, $event->attempts);
	Assert::same('done', $database->query('SELECT status FROM queue_jobs')->fetchField());
	Assert::same(['info: Worker started', 'info: Worker stopped'], $log->messages());
	Assert::true(is_file(TEMP_DIR . '/worker.heartbeat'));
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
