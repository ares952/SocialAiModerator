<?php

declare(strict_types=1);

use App\Account\Credentials;
use App\Account\TokenType;
use App\Audit\AuditLog;
use App\Core\Encryptor;
use App\Ingest\WebhookBatch;
use App\Knowledge\SourceType;
use App\Moderation\Mode;
use App\Moderation\PolicySettings;
use App\Outbox\InvalidAction;
use App\Queue\JobQueue;
use Nette\Utils\Json;
use Tester\Assert;
use Tests\Fakes\FakeMetaClient;
use Tests\Fakes\FakeOpenAiClient;
use Tests\Support\TestDatabase;
use Tests\Support\TestLog;
use Tests\Support\TestServices;

require __DIR__ . '/../../bootstrap.php';

// FAQ comment -> reply action -> reply.draft -> human approval (possibly edited) -> outbox ->
// ActionDispatcher (specification §5.3, FR-C05, FR-M08). Fake Meta and OpenAI only.

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
	'email' => 'moderator@example.test',
	'name' => 'Moderator',
	'role' => 'moderator',
	'password_hash' => 'x',
]);
$database->query('INSERT INTO social_accounts ?', [
	'platform' => 'instagram',
	'external_id' => 'ig:1000000000000002',
	'name' => 'Test IG',
	'status' => 'active',
]);
$accountId = (int) $database->getInsertId();
(new Credentials($database, $encryptor, new AuditLog($database)))
	->replace($accountId, 'synthetic-system-user-token', TokenType::SystemUser, ['instagram_manage_comments'], null, null, null);

$knowledge = TestServices::knowledge($database);
$pricing = $knowledge->createDraft('pricing_current', 'cs', SourceType::Pricing, 'Ceník', 'Online předplatné stojí 289 Kč měsíčně.', null, null, 1);
$knowledge->publish($pricing, 1);
TestServices::policies($database)->save(Mode::Assisted, new PolicySettings, 1);

$client = new FakeOpenAiClient;
$log = new TestLog;
$decider = TestServices::eventDecider($database, $encryptor, $queue, $client);
$drafter = TestServices::drafter($database, $encryptor, $queue, $client, $log->logger);
$actions = TestServices::actions($database, $encryptor, $queue);
$meta = new FakeMetaClient;
$dispatcher = TestServices::dispatcher($database, $encryptor, $meta, $log->logger);

// An Instagram question decided as a reply; returns the action ID.
$question = static function (string $commentId, array $knowledgeKeys = ['pricing_current']) use ($database, $encryptor, $queue, $client, $decider): int {
	TestServices::eventStore($database, $encryptor, $queue)->save(WebhookBatch::parse(Json::encode([
		'object' => 'instagram',
		'entry' => [
			[
				'id' => '1000000000000002',
				'time' => 1763640000,
				'changes' => [
					[
						'field' => 'comments',
						'value' => [
							'id' => $commentId,
							'text' => 'Kolik stojí předplatné?',
							'from' => [
								'id' => "22$commentId",
								'username' => 'synthetic_customer',
							],
							'media' => [
								'id' => '3300000000000001',
							],
						],
					],
				],
			],
		],
	])));
	$eventId = (int) $database->query('SELECT MAX(id) FROM social_events')->fetchField();
	TestServices::eventProcessor($database, $encryptor, $queue)->process($eventId);
	$client->queue(FakeOpenAiClient::classification([
		'knowledge_required' => $knowledgeKeys,
	]));
	$decider->decide($eventId, lastAttempt: false);

	return (int) ($database->query('SELECT id FROM moderation_actions WHERE event_id = ?', $eventId)->fetchField()
		?? throw new LogicException('No reply action.'));
};
$action = static fn(int $id) => $database->query(
	'SELECT a.*, x.id AS outbox_id, x.status AS outbox_status FROM moderation_actions a LEFT JOIN outbox x ON x.action_id = a.id WHERE a.id = ?',
	$id,
)->fetch() ?? throw new LogicException('No action.');
$reply = static fn(string $text, bool $canAnswer = true): array => [
	'can_answer' => $canAnswer,
	'reply' => $text,
	'used_knowledge' => $canAnswer ? ['pricing_current'] : [],
	'reason' => 'Synthetic reason.',
];


test('a FAQ comment gets a draft from the knowledge; an edited approval is sent once', function () use ($question, $action, $reply, $client, $drafter, $actions, $dispatcher, $meta, $database, $pricing): void {
	$id = $question('17900000000000001');
	$row = $action($id);
	Assert::same(['reply', 'pending', 0, null], [$row->type, $row->status, $row->automatic, $row->text_encrypted]);
	Assert::same(1, (int) $database->query("SELECT COUNT(*) FROM queue_jobs WHERE type = 'reply.draft' AND subject_id = ?", $id)->fetchField());
	Assert::exception(fn() => $actions->approve($id, 1), InvalidAction::class, 'actions.error.no-draft');

	$client->queue($reply('Online předplatné stojí 289 Kč měsíčně.'));
	$drafter->draft($id, lastAttempt: false);
	$row = $action($id);
	Assert::same('Online předplatné stojí 289 Kč měsíčně.', $actions->text($row));
	Assert::notContains('289', (string) $row->text_encrypted, 'The reply is encrypted at rest.');
	Assert::same([['id' => $pricing, 'key' => 'pricing_current', 'version' => 1]], Json::decode((string) $row->knowledge_json, forceArrays: true));
	Assert::same(['reply-model-2026', 'reply-v2'], [$row->model, $row->prompt_version]);
	Assert::contains('"text":"Kolik stojí předplatné?"', $client->requests[2]['input']);

	Assert::exception(fn() => $actions->approve($id, 1, '  '), InvalidAction::class, 'actions.error.text');
	$actions->approve($id, 1, ' Dobrý den, online předplatné stojí 289 Kč měsíčně. ');
	$row = $action($id);
	Assert::same(['approved', 1, 'Dobrý den, online předplatné stojí 289 Kč měsíčně.'], [$row->status, $row->edited, $actions->text($row)]);
	Assert::same('edited', $database->query("SELECT details FROM admin_audit WHERE action = 'action.approve' AND target_id = ?", $id)->fetchField());

	$dispatcher->send($row->outbox_id, lastAttempt: false);
	Assert::same([
		'platform' => App\Meta\Platform::Instagram,
		'token' => 'synthetic-system-user-token',
		'comment' => 'ig:17900000000000001',
		'reply' => 'Dobrý den, online předplatné stojí 289 Kč měsíčně.',
	], $meta->writes[0]);
	$row = $action($id);
	Assert::same(['sent', ['reply_id' => 'ig:reply_1']], [$row->status, Json::decode((string) $row->result_json, forceArrays: true)]);
	Assert::same('visible', $database->query("SELECT visibility FROM social_comments WHERE external_id = 'ig:17900000000000001'")->fetchField());
});


test('a comment gets at most one reply', function () use ($question, $action, $reply, $client, $drafter, $actions, $dispatcher, $meta, $database): void {
	$id = $question('17900000000000002');
	$client->queue($reply('Online předplatné stojí 289 Kč měsíčně.'));
	$drafter->draft($id, lastAttempt: false);
	$actions->approve($id, 1);
	$dispatcher->send($action($id)->outbox_id, lastAttempt: false);
	Assert::same('sent', $action($id)->status);

	// A second reply action for the same comment (e.g. a repeated decision).
	$database->query('INSERT INTO moderation_actions ?', [
		'comment_id' => $action($id)->comment_id,
		'type' => 'reply',
		'status' => 'pending',
		'automatic' => false,
		'external_target_id' => 'ig:17900000000000002',
		'text_encrypted' => $action($id)->text_encrypted,
	]);
	$second = (int) $database->getInsertId();
	$actions->approve($second, 1);
	$dispatcher->send($action($second)->outbox_id, lastAttempt: false);
	Assert::same(['blocked', ['already_replied']], [$action($second)->status, Json::decode((string) $action($second)->reasons_json, forceArrays: true)]);
	Assert::count(2, $meta->writes);
});


test('without an answer, knowledge or a valid draft the reply stays with a human', function () use ($question, $action, $reply, $client, $drafter, $knowledge, $pricing): void {
	$id = $question('17900000000000003');
	$client->queue($reply('', canAnswer: false));
	$drafter->draft($id, lastAttempt: false);
	Assert::same(['blocked', '["reply_not_answerable"]', null], [$action($id)->status, $action($id)->reasons_json, $action($id)->text_encrypted]);

	$id = $question('17900000000000004');
	$client->queue($reply('Online předplatné stojí 199 Kč měsíčně.'));
	$drafter->draft($id, lastAttempt: false);
	Assert::same(['blocked', '["reply_invalid"]'], [$action($id)->status, $action($id)->reasons_json]);

	$id = $question('17900000000000005');
	$client->queue(new App\Ai\OpenAiFailure('HTTP 503', transient: true));
	Assert::exception(fn() => $drafter->draft($id, lastAttempt: false), App\Ai\OpenAiFailure::class);
	Assert::same('pending', $action($id)->status);
	$client->queue(new App\Ai\OpenAiFailure('HTTP 503', transient: true));
	$drafter->draft($id, lastAttempt: true);
	Assert::same(['blocked', '["reply_unavailable"]'], [$action($id)->status, $action($id)->reasons_json]);

	$id = $question('17900000000000006');
	$knowledge->unpublish($pricing, 1);
	$requests = count($client->requests);
	$drafter->draft($id, lastAttempt: false);
	Assert::same(['blocked', '["knowledge_missing"]'], [$action($id)->status, $action($id)->reasons_json]);
	Assert::count($requests, $client->requests, 'Without knowledge the model is not called.');
});
