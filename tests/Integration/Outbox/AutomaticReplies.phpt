<?php

declare(strict_types=1);

use App\Account\Credentials;
use App\Account\TokenType;
use App\Audit\AuditLog;
use App\Core\Encryptor;
use App\Ingest\WebhookBatch;
use App\Knowledge\SourceType;
use App\Moderation\Category;
use App\Moderation\Mode;
use App\Moderation\PolicySettings;
use App\Queue\JobQueue;
use Nette\Utils\Json;
use Tester\Assert;
use Tests\Fakes\FakeMetaClient;
use Tests\Fakes\FakeOpenAiClient;
use Tests\Support\TestDatabase;
use Tests\Support\TestLog;
use Tests\Support\TestServices;

require __DIR__ . '/../../bootstrap.php';

// Automatic replies (FR-C06, FR-M04, specification §8.1, §10 M4/M5): only in automatic mode, in an
// allowed category, with a checked draft and within the limits per conversation and per account;
// a reply the rules no longer allow without a human goes back to the approval queue.
// Fake Meta and OpenAI only; synthetic content.

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
	'platform' => 'facebook',
	'external_id' => 'fb:1000000000000001',
	'name' => 'Test Page',
	'status' => 'active',
]);
$accountId = (int) $database->getInsertId();
(new Credentials($database, $encryptor, new AuditLog($database)))
	->replace($accountId, 'synthetic-page-token', TokenType::Page, ['pages_messaging'], null, null, null);

$knowledge = TestServices::knowledge($database);
$knowledge->publish($knowledge->createDraft('pricing_current', 'cs', SourceType::Pricing, 'Ceník', 'Online předplatné stojí 289 Kč měsíčně.', null, null, 1), 1);
$policies = TestServices::policies($database);
$automatic = static fn(Mode $mode = Mode::Automatic) => $policies->save($mode, new PolicySettings(
	autoReplyCategories: [Category::Faq],
	maxRepeats: null,
	autoRepliesPerConversation: 1,
	autoRepliesPerDay: 2,
), 1);
$automatic();

$client = new FakeOpenAiClient;
$log = new TestLog;
$decider = TestServices::eventDecider($database, $encryptor, $queue, $client);
$drafter = TestServices::drafter($database, $encryptor, $queue, $client, $log->logger);
$actions = TestServices::actions($database, $encryptor, $queue);
$meta = new FakeMetaClient;
$dispatcher = TestServices::dispatcher($database, $encryptor, $meta, $log->logger);

// One Messenger message or Page comment, decided and drafted; returns the action ID.
$ask = static function (array $entry, string $category = 'faq') use ($database, $encryptor, $queue, $client, $decider, $drafter): int {
	TestServices::eventStore($database, $encryptor, $queue)->save(WebhookBatch::parse(Json::encode([
		'object' => 'page',
		'entry' => [
			[
				'id' => '1000000000000001',
				'time' => time() * 1000,
			] + $entry,
		],
	])));
	$eventId = (int) $database->query('SELECT MAX(id) FROM social_events')->fetchField();
	TestServices::eventProcessor($database, $encryptor, $queue)->process($eventId);
	$client->queue(FakeOpenAiClient::classification([
		'category' => $category,
		'knowledge_required' => ['pricing_current'],
	]));
	$decider->decide($eventId, lastAttempt: false);
	$actionId = (int) ($database->query('SELECT id FROM moderation_actions WHERE event_id = ?', $eventId)->fetchField()
		?? throw new LogicException('No reply action.'));
	$client->queue([
		'can_answer' => true,
		'reply' => 'Online předplatné stojí 289 Kč měsíčně.',
		'used_knowledge' => ['pricing_current'],
		'reason' => 'Synthetic reason.',
	]);
	$drafter->draft($actionId, lastAttempt: false);

	return $actionId;
};
$message = static fn(string $psid, string $mid): array => [
	'messaging' => [
		[
			'sender' => ['id' => $psid],
			'recipient' => ['id' => '1000000000000001'],
			'timestamp' => (int) (microtime(true) * 1000),
			'message' => ['mid' => $mid, 'text' => "Kolik stojí předplatné? ($mid)"],
		],
	],
];
$comment = static fn(string $commentId): array => [
	'changes' => [
		[
			'field' => 'feed',
			'value' => [
				'item' => 'comment',
				'verb' => 'add',
				'comment_id' => $commentId,
				'created_time' => time(),
				'message' => "Kolik stojí předplatné? ($commentId)",
				'from' => ['id' => "21$commentId", 'name' => 'Synthetic Customer'],
			],
		],
	],
];
$action = static fn(int $id) => $database->query(
	'SELECT a.*, x.id AS outbox_id, x.status AS outbox_status FROM moderation_actions a LEFT JOIN outbox x ON x.action_id = a.id WHERE a.id = ?',
	$id,
)->fetch() ?? throw new LogicException('No action.');
$reasons = static fn(int $id): array => Json::decode((string) ($action($id)->reasons_json ?? '[]'), forceArrays: true);


test('an allowed FAQ message is answered without a human', function () use ($ask, $message, $action, $dispatcher, $meta): void {
	$id = $ask($message('2400000000000001', 'm_1'));
	$row = $action($id);
	Assert::same(['approved', 1, null, 'pending'], [$row->status, $row->automatic, $row->reviewed_by, $row->outbox_status], 'Queued with its draft.');

	$dispatcher->send($row->outbox_id, lastAttempt: false);
	Assert::same('sent', $action($id)->status);
	Assert::same('fb:2400000000000001', $meta->writes[0]['recipient'] ?? null);
});


test('over the conversation limit the reply goes back to a human, who may still send it', function () use ($ask, $message, $action, $actions, $dispatcher, $meta, $reasons, $database): void {
	$id = $ask($message('2400000000000001', 'm_2'));
	$dispatcher->send($action($id)->outbox_id, lastAttempt: false);
	$row = $action($id);
	Assert::same(['pending', null], [$row->status, $row->outbox_id]);
	Assert::same(['auto_reply_limit_conversation'], $reasons($id));
	Assert::count(1, $meta->writes);

	$actions->approve($id, 1);
	$dispatcher->send($action($id)->outbox_id, lastAttempt: false);
	Assert::same(['sent', 1], [$action($id)->status, $action($id)->reviewed_by]);
	Assert::count(2, $meta->writes);
	Assert::same(0, (int) $database->query("SELECT COUNT(*) FROM outbox WHERE status = 'pending'")->fetchField());
});


test('the daily limit of the account counts automatic replies only', function () use ($ask, $comment, $action, $dispatcher, $meta, $reasons): void {
	$first = $ask($comment('c1'));
	$dispatcher->send($action($first)->outbox_id, lastAttempt: false);
	Assert::same('sent', $action($first)->status, 'The human-approved reply does not count.');
	Assert::same('Online předplatné stojí 289 Kč měsíčně.', $meta->writes[2]['reply'] ?? null);

	$second = $ask($comment('c2'));
	$dispatcher->send($action($second)->outbox_id, lastAttempt: false);
	Assert::same(['pending'], [$action($second)->status]);
	Assert::same(['auto_reply_limit_day'], $reasons($second));
	Assert::count(3, $meta->writes);
});


test('a switch to assisted mode returns queued automatic replies to a human', function () use ($ask, $message, $action, $dispatcher, $reasons, $automatic): void {
	$id = $ask($message('2400000000000003', 'm_3'));
	Assert::same('approved', $action($id)->status);
	$automatic(Mode::Assisted);
	$dispatcher->send($action($id)->outbox_id, lastAttempt: false);
	Assert::same('pending', $action($id)->status);
	Assert::same(['mode_assisted', 'auto_reply_limit_day'], $reasons($id), 'The daily limit is reached as well.');
	$automatic();
});


test('a category that is not allowed or a takeover never answers automatically', function () use ($ask, $message, $action, $dispatcher, $reasons, $database, $meta): void {
	Assert::exception(fn() => $ask($message('2400000000000004', 'm_4'), category: 'praise'), LogicException::class, 'No reply action.');

	$id = $ask($message('2400000000000005', 'm_5'));
	$database->query("UPDATE social_conversations SET human_takeover_at = NOW(3) WHERE customer_external_id = 'fb:2400000000000005'");
	$dispatcher->send($action($id)->outbox_id, lastAttempt: false);
	Assert::same(['blocked', ['auto_reply_limit_day', 'human_takeover']], [$action($id)->status, $reasons($id)], 'A takeover blocks for good, also when the limit is reached.');
	Assert::count(3, $meta->writes);
});
