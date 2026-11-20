<?php

declare(strict_types=1);

use App\Ai\OpenAiFailure;
use App\Audit\AuditLog;
use App\Console\EvaluationImportCommand;
use App\Core\Encryptor;
use App\Evaluation\EvaluationRuns;
use App\Evaluation\EvaluationSamples;
use App\Evaluation\InvalidEvaluation;
use App\Queue\JobQueue;
use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Symfony\Component\Console\Tester\CommandTester;
use Tester\Assert;
use Tests\Fakes\FakeOpenAiClient;
use Tests\Support\TestDatabase;
use Tests\Support\TestServices;

require __DIR__ . '/../../bootstrap.php';

// Synthetic samples only.

$database = TestDatabase::create();
$database->query('INSERT INTO operators ?', [
	'email' => 'admin@example.test',
	'name' => 'Admin',
	'role' => 'admin',
	'password_hash' => 'x',
]);
$encryptor = new Encryptor(base64_encode(random_bytes(32)));
$samples = new EvaluationSamples($database, $encryptor, new AuditLog($database));
$queue = new JobQueue($database, maxAttempts: 3, backoffSeconds: 1, backoffCapSeconds: 1, leaseSeconds: 60);
$runs = static fn(FakeOpenAiClient $client, string $model = 'test-model'): EvaluationRuns => new EvaluationRuns(
	$database,
	TestServices::classifier($database, $client, $model),
	TestServices::policies($database),
	$queue,
	new AuditLog($database),
);


test('samples are imported all or nothing and stored encrypted', function () use ($samples, $database): void {
	$valid = [
		[
			'platform' => 'facebook',
			'kind' => 'comment',
			'language' => 'cs',
			'text' => 'Kurz mi vůbec nepomohl, škoda peněz.',
			'expected_category' => 'legitimate_criticism',
			'expected_action' => 'keep',
		],
		[
			'platform' => 'instagram',
			'kind' => 'comment',
			'language' => 'en',
			'text' => 'Follow me for free followers!!! www.example.test',
			'expected_category' => 'spam',
			'expected_action' => 'hide',
		],
		[
			'platform' => 'facebook',
			'kind' => 'message',
			'language' => 'es',
			'text' => 'Quiero un reembolso de mi pago.',
			'expected_category' => 'refund',
			'expected_action' => 'handoff',
			'note' => 'refund request',
		],
	];
	$command = new CommandTester(new EvaluationImportCommand($samples, $database));

	FileSystem::write(TEMP_DIR . '/invalid.json', Json::encode([...$valid, ['platform' => 'facebook', 'kind' => 'comment', 'language' => 'cs', 'text' => 'x', 'expected_category' => 'nonsense', 'expected_action' => 'keep']]));
	Assert::same(1, $command->execute(['file' => TEMP_DIR . '/invalid.json']));
	Assert::same(0, $samples->count());

	FileSystem::write(TEMP_DIR . '/samples.json', Json::encode($valid));
	Assert::same(0, $command->execute(['file' => TEMP_DIR . '/samples.json']));
	Assert::same(3, $samples->count());
	Assert::same('Quiero un reembolso de mi pago.', $samples->all()[2]->text);
	Assert::notContains('reembolso', (string) $database->query('SELECT text_encrypted FROM evaluation_samples WHERE id = 3')->fetchField());
});


test('a run needs a model and samples', function () use ($runs): void {
	Assert::exception(fn() => $runs(new FakeOpenAiClient, model: '')->start(1), InvalidEvaluation::class, 'evaluation.error.no-model');
});


test('a run evaluates every sample in the worker and reports the critical errors', function () use ($runs, $database, $encryptor, $queue): void {
	$client = (new FakeOpenAiClient)
		// The model misreads the criticism as spam; the report must expose it as a critical error.
		->queue(FakeOpenAiClient::classification(['category' => 'spam', 'recommended_action' => 'hide', 'confidence' => 0.99, 'spam_probability' => 0.99]))
		->queue(FakeOpenAiClient::classification(['category' => 'spam', 'language' => 'en', 'recommended_action' => 'hide', 'confidence' => 0.99, 'spam_probability' => 0.99]))
		->queue(new OpenAiFailure('HTTP 503', transient: true))
		->queue(FakeOpenAiClient::classification(['category' => 'refund', 'language' => 'es', 'recommended_action' => 'reply', 'requires_human' => true]));
	$runId = $runs($client)->start(1);
	Assert::same(3, $database->query("SELECT COUNT(*) FROM queue_jobs WHERE type = 'evaluation.sample'")->fetchField());

	$runner = TestServices::evaluationRunner($database, $encryptor, $client);
	while (($job = $queue->reserve('test')) !== null) {
		try {
			$runner->evaluate($job->subjectId, $queue->isLastAttempt($job));
			$queue->finish($job);
		} catch (OpenAiFailure) {
			$queue->fail($job, 'transient');
			$database->query('UPDATE queue_jobs SET available_at = NOW(3)');
		}
	}

	$report = $runs($client)->report($runId);
	Assert::same(3, $report['done']);
	Assert::same(0, $report['failed']);
	Assert::same(2, $report['categoryCorrect']);
	Assert::same(1, $report['criticismHidden']);
	Assert::same(0, $report['missedHandoff']);
	Assert::same(1, $report['falseHide']);
	Assert::same(0, $report['automatic']);
	Assert::same(1, $report['confusion']['legitimate_criticism']['spam']);

	$results = $runs($client)->results($runId);
	Assert::same(['hide', 'hide', 'handoff'], array_map(fn($row) => $row->action, $results));
	Assert::contains('mode_observe', Json::decode($results[0]->reasons_json, forceArrays: true));
});


test('a permanent failure marks the sample failed without retries', function () use ($runs, $database, $encryptor): void {
	$client = (new FakeOpenAiClient)
		->queue(FakeOpenAiClient::classification(['category' => 'nonsense']))
		->queue(new OpenAiFailure('HTTP 400', transient: false))
		->queue(new OpenAiFailure('HTTP 503', transient: true));
	$runId = $runs($client)->start(1);
	$runner = TestServices::evaluationRunner($database, $encryptor, $client);
	$ids = $database->query('SELECT id FROM evaluation_results WHERE run_id = ? ORDER BY id', $runId)->fetchPairs(null, 'id');

	$runner->evaluate($ids[0], lastAttempt: false);
	$runner->evaluate($ids[1], lastAttempt: false);
	$runner->evaluate($ids[2], lastAttempt: true);

	$report = $runs($client)->report($runId);
	Assert::same(3, $report['failed']);
	Assert::match('App\Ai\InvalidModelOutput: %a%', $runs($client)->results($runId)[0]->error);
});
