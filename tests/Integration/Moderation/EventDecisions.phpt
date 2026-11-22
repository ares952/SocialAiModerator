<?php

declare(strict_types=1);

use App\Ai\OpenAiFailure;
use App\Audit\AuditLog;
use App\Core\Encryptor;
use App\Ingest\WebhookBatch;
use App\Moderation\AutomationSwitch;
use App\Moderation\Decisions;
use App\Queue\JobQueue;
use Nette\Utils\Json;
use Tester\Assert;
use Tests\Fakes\FakeOpenAiClient;
use Tests\Support\TestDatabase;
use Tests\Support\TestServices;

require __DIR__ . '/../../bootstrap.php';

// Stored comment/message -> event.process -> event.decide: classification with the fake OpenAI
// client, PolicyEngine decision in observe mode, stored decision (specification §5, Milestone 2).
// Synthetic content only; nothing is sent to Meta.

$database = TestDatabase::create();
$encryptor = new Encryptor(base64_encode(random_bytes(32)));
$queue = new JobQueue(
	$database,
	maxAttempts: 3,
	backoffSeconds: 10,
	backoffCapSeconds: 25,
	leaseSeconds: 600,
);
$database->query('INSERT INTO operators ?', [
	'email' => 'admin@example.test',
	'name' => 'Admin',
	'role' => 'admin',
	'password_hash' => 'x',
]);
$database->query('INSERT INTO social_accounts ?', [
	'platform' => 'facebook',
	'external_id' => 'fb:1000000000000001',
	'name' => 'Test Page',
	'status' => 'active',
]);
$client = new FakeOpenAiClient;
$decider = TestServices::eventDecider($database, $encryptor, $queue, $client);
$decisions = new Decisions($database, $encryptor);

// Stores and processes one Page webhook item; returns the event ID.
$receive = static function (string $list, array $item) use ($database, $encryptor, $queue): int {
	TestServices::eventStore($database, $encryptor, $queue)->save(WebhookBatch::parse(Json::encode([
		'object' => 'page',
		'entry' => [
			[
				'id' => '1000000000000001',
				'time' => 1763640000,
				$list => [$item],
			],
		],
	])));
	$id = (int) $database->query('SELECT MAX(id) FROM social_events')->fetchField();
	TestServices::eventProcessor($database, $encryptor, $queue)->process($id);

	return $id;
};

$comment = static fn (string $commentId, string $text): array => [
	'field' => 'feed',
	'value' => [
		'item' => 'comment',
		'verb' => 'add',
		'comment_id' => $commentId,
		'created_time' => 1763640000,
		'message' => $text,
		'from' => ['id' => '2100000000000001', 'name' => 'Synthetic Customer'],
	],
];

$decideJobs = static fn (int $eventId): int => (int) $database->query(
	"SELECT COUNT(*) FROM queue_jobs WHERE type = 'event.decide' AND subject_id = ?",
	$eventId,
)->fetchField();


test('a comment with text is queued, classified and decided without any external action', function () use ($receive, $comment, $decideJobs, $decider, $decisions, $client, $database): void {
	$id = $receive('changes', $comment('c1', 'Synthetic question: how much is it? mail me at someone@example.com'));
	Assert::same(1, $decideJobs($id));

	$client->queue(FakeOpenAiClient::classification());
	$decider->decide($id, lastAttempt: false);

	Assert::count(2, $client->requests, 'moderation + classification');
	Assert::contains('Synthetic question', $client->requests[1]['input']);
	Assert::notContains('someone@example.com', $client->requests[1]['input'], 'Personal data is redacted.');
	Assert::notContains('Synthetic Customer', $client->requests[1]['input'], 'The author is not sent.');

	$rows = $decisions->forEvent($id);
	Assert::count(1, $rows);
	$decision = $rows[0];
	Assert::same(['handoff', 0, 'faq', 'test-model-2026', 'classifier-v2', 0, 120, 40, null], [
		$decision->action,
		$decision->automatic,
		$decision->category,
		$decision->model,
		$decision->prompt_version,
		$decision->policy_version,
		$decision->input_tokens,
		$decision->output_tokens,
		$decision->error,
	]);
	$result = Json::decode($decision->result_json, forceArrays: true);
	Assert::same(['knowledge_missing'], $result['reasons'], 'No published knowledge, so the question is handed off.');
	Assert::same('none', $result['classification']['abuse_type']);
	Assert::same('cs', $result['classification']['language']);
	Assert::same('Question about the price.', $decisions->reason($decision));
	Assert::notContains('Question about the price', (string) $database->query('SELECT CAST(reason_encrypted AS CHAR) FROM moderation_decisions')->fetchField());

	$decider->decide($id, lastAttempt: false);
	Assert::count(2, $client->requests, 'A classified event is not sent to the model again.');
	Assert::false($decider->request($id));
	Assert::same('review', $database->query('SELECT status FROM social_events WHERE id = ?', $id)->fetchField());
});


test('a transient failure is retried; on the last attempt it is stored as a fail-closed review', function () use ($receive, $comment, $decider, $decisions, $client, $decideJobs): void {
	$id = $receive('changes', $comment('c2', 'Synthetic text'));

	$client->queue(new OpenAiFailure('HTTP 503', transient: true));
	Assert::exception(fn() => $decider->decide($id, lastAttempt: false), OpenAiFailure::class);
	Assert::same([], $decisions->forEvent($id));

	$client->queue(new OpenAiFailure('HTTP 503', transient: true));
	$decider->decide($id, lastAttempt: true);
	$decision = $decisions->forEvent($id)[0];
	Assert::same(['review', 0, null], [$decision->action, $decision->automatic, $decision->category]);
	Assert::same('App\Ai\OpenAiFailure: HTTP 503', $decision->error);
	Assert::same(['classification_unavailable'], Json::decode($decision->result_json, forceArrays: true)['reasons']);
	Assert::null($decisions->reason($decision));

	// A failed decision can be requested again, and the next job classifies the event.
	Assert::true($decider->request($id));
	Assert::same(2, $decideJobs($id));
	$client->queue(FakeOpenAiClient::classification(['category' => 'spam', 'recommended_action' => 'hide', 'confidence' => 0.99, 'spam_probability' => 0.99]));
	$decider->decide($id, lastAttempt: false);
	Assert::same(['hide', 0, 'spam'], [$decisions->forEvent($id)[0]->action, $decisions->forEvent($id)[0]->automatic, $decisions->forEvent($id)[0]->category]);
	Assert::count(2, $decisions->forEvent($id));
});


test('invalid model output and a permanent failure end as review at once', function () use ($receive, $comment, $decider, $decisions, $client): void {
	$id = $receive('changes', $comment('c3', 'Synthetic text'));
	$client->queue(['category' => 'nonsense']);
	$decider->decide($id, lastAttempt: false);
	$decision = $decisions->forEvent($id)[0];
	Assert::same('review', $decision->action);
	Assert::match('App\Ai\InvalidModelOutput: %a%', $decision->error);

	$id = $receive('changes', $comment('c4', 'Synthetic text'));
	$client->queue(new OpenAiFailure('HTTP 401', transient: false));
	$decider->decide($id, lastAttempt: false);
	Assert::same('App\Ai\OpenAiFailure: HTTP 401', $decisions->forEvent($id)[0]->error);
});


test('the panic switch and handoff categories are part of the decision', function () use ($receive, $comment, $decider, $decisions, $client, $database): void {
	$switch = new AutomationSwitch($database, new AuditLog($database));
	$switch->pause(null, 'test', 1);
	$id = $receive('changes', $comment('c5', 'Synthetic text'));
	$client->queue(FakeOpenAiClient::classification([
		'category' => 'spam',
		'recommended_action' => 'hide',
		'confidence' => 0.99,
		'spam_probability' => 0.99,
	]));
	$decider->decide($id, lastAttempt: false);
	Assert::contains('automation_paused', Json::decode($decisions->forEvent($id)[0]->result_json, forceArrays: true)['reasons']);
	$switch->resume(null, 1);

	$id = $receive('changes', $comment('c6', 'Synthetic complaint'));
	$client->queue(FakeOpenAiClient::classification(['category' => 'refund', 'recommended_action' => 'reply']));
	$decider->decide($id, lastAttempt: false);
	Assert::same('handoff', $decisions->forEvent($id)[0]->action);
});


test('messages are decided, echoes, reactions and texts without content are not', function () use ($receive, $decideJobs, $decider, $client, $database): void {
	$message = static fn (string $mid, array $extra = []): array => [
		'sender' => ['id' => '2400000000000001'],
		'recipient' => ['id' => '1000000000000001'],
		'timestamp' => 1763640000000,
		'message' => $extra + ['mid' => $mid, 'text' => 'Synthetic message'],
	];

	Assert::same(1, $decideJobs($receive('messaging', $message('m_in'))));
	Assert::same(0, $decideJobs($receive('messaging', $message('m_echo', ['is_echo' => true]))));
	Assert::same(0, $decideJobs($receive('messaging', $message('m_attachment', ['text' => '  ']))));
	Assert::same(0, $decideJobs($receive('changes', [
		'field' => 'feed',
		'value' => ['item' => 'reaction', 'verb' => 'add', 'reaction_type' => 'like'],
	])));

	$echo = (int) $database->query("SELECT id FROM social_events WHERE external_id = 'fb:m_echo'")->fetchField();
	$before = count($client->requests);
	$decider->decide($echo, lastAttempt: false);
	Assert::false($decider->request($echo));
	Assert::count($before, $client->requests);
});


test('a text repeated by the same author goes to review instead of being kept', function () use ($receive, $comment, $decider, $decisions, $client): void {
	$praise = FakeOpenAiClient::classification([
		'category' => 'praise',
		'sentiment' => 'positive',
		'recommended_action' => 'keep',
	]);
	$results = [];
	foreach (['r1', 'r2', 'r3'] as $commentId) {
		// The repeat window counts from the Meta time of the comment.
		$item = $comment($commentId, 'Synthetic  praise, LOVE it');
		$item['value']['created_time'] = time();
		$id = $receive('changes', $item);
		$client->queue($praise);
		$decider->decide($id, lastAttempt: false);
		$decision = $decisions->forEvent($id)[0];
		$results[] = [$decision->action, Json::decode($decision->result_json, forceArrays: true)['reasons']];
	}

	Assert::same([
		['keep', []],
		['keep', []],
		['review', ['repeated_text']],
	], $results, 'The default policy allows one earlier identical text.');
});


test('own comments of the Page are ignored and never decided', function () use ($receive, $decideJobs, $database): void {
	$id = $receive('changes', [
		'field' => 'feed',
		'value' => [
			'item' => 'comment',
			'verb' => 'add',
			'comment_id' => 'own1',
			'created_time' => 1763640000,
			'message' => 'Synthetic reply of the Page',
			'from' => ['id' => '1000000000000001', 'name' => 'Test Page'],
		],
	]);
	Assert::same(0, $decideJobs($id));
	Assert::same('ignored', $database->query('SELECT status FROM social_events WHERE id = ?', $id)->fetchField());
	Assert::same(1, $database->query("SELECT own FROM social_comments WHERE external_id = 'fb:own1'")->fetchField());
});


test('events of an inactive account are never decided', function () use ($receive, $comment, $decider, $database): void {
	$database->query("UPDATE social_accounts SET status = 'disabled'");
	$id = $receive('changes', $comment('c7', 'Synthetic text'));
	Assert::same('ignored', $database->query('SELECT status FROM social_events WHERE id = ?', $id)->fetchField());
	Assert::false($decider->request($id));
	$database->query("UPDATE social_accounts SET status = 'active'");
});
