<?php

declare(strict_types=1);

use App\Account\Credentials;
use App\Account\TokenType;
use App\Audit\AuditLog;
use App\Conversation\Conversations;
use App\Conversation\Inbox;
use App\Conversation\InboxFilter;
use App\Core\Encryptor;
use App\Ingest\WebhookBatch;
use App\Knowledge\SourceType;
use App\Meta\Platform;
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

// Unified inbox (specification §3.5, FR-M01–M08, safety invariant 8): replies to private messages
// only inside the window at the moment of sending, at most once, never after an echo, never an AI
// text while an operator has the conversation. Fake Meta and OpenAI only; synthetic content.

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
$policies->save(Mode::Assisted, new PolicySettings(maxRepeats: null), 1);

$client = new FakeOpenAiClient;
$log = new TestLog;
$decider = TestServices::eventDecider($database, $encryptor, $queue, $client);
$drafter = TestServices::drafter($database, $encryptor, $queue, $client, $log->logger);
$actions = TestServices::actions($database, $encryptor, $queue);
$conversations = TestServices::conversations($database, $encryptor);
$inbox = new Inbox($database, $encryptor);
$meta = new FakeMetaClient;
$dispatcher = TestServices::dispatcher($database, $encryptor, $meta, $log->logger);

// Stores and processes one Messenger item (now); returns the event ID.
$receive = static function (array $item) use ($database, $encryptor, $queue): int {
	TestServices::eventStore($database, $encryptor, $queue)->save(WebhookBatch::parse(Json::encode([
		'object' => 'page',
		'entry' => [
			[
				'id' => '1000000000000001',
				'time' => time() * 1000,
				'messaging' => [$item],
			],
		],
	])));
	$id = (int) $database->query('SELECT MAX(id) FROM social_events')->fetchField();
	TestServices::eventProcessor($database, $encryptor, $queue)->process($id);

	return $id;
};
// $ago: milliseconds before now
$customer = static fn(string $psid, string $mid, string $text, int $ago = 0): array => [
	'sender' => ['id' => $psid],
	'recipient' => ['id' => '1000000000000001'],
	'timestamp' => (int) (microtime(true) * 1000) - $ago,
	'message' => ['mid' => $mid, 'text' => $text],
];
// $message: extra fields of the echoed message (app_id, metadata)
$echo = static fn(string $psid, string $mid, string $text, int $ago = -1000, array $message = []): array => [
	'sender' => ['id' => '1000000000000001'],
	'recipient' => ['id' => $psid],
	'timestamp' => (int) (microtime(true) * 1000) - $ago,
	'message' => $message + ['mid' => $mid, 'text' => $text, 'is_echo' => true],
];
// A customer question decided as a reply with a draft; returns the action ID.
$question = static function (string $psid, string $mid) use ($receive, $customer, $client, $decider, $drafter, $database): int {
	$eventId = $receive($customer($psid, $mid, 'Kolik stojí předplatné?'));
	$client->queue(FakeOpenAiClient::classification([
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
$action = static fn(int $id) => $database->query(
	'SELECT a.*, x.id AS outbox_id FROM moderation_actions a LEFT JOIN outbox x ON x.action_id = a.id WHERE a.id = ?',
	$id,
)->fetch() ?? throw new LogicException('No action.');
$reasons = static fn(int $id): array => Json::decode((string) $action($id)->reasons_json, forceArrays: true);
$conversationOf = static fn(string $psid): int => (int) $database->query(
	'SELECT id FROM social_conversations WHERE customer_external_id = ?',
	"fb:$psid",
)->fetchField();


test('a message gets a draft with the conversation as context, approved and sent in the window', function () use ($receive, $customer, $echo, $question, $action, $actions, $dispatcher, $meta, $client, $inbox, $conversationOf): void {
	$receive($customer('2400000000000001', 'm_hello', 'Dobrý den, mám dotaz.', ago: 3000));
	$receive($echo('2400000000000001', 'm_hello_reply', 'Dobrý den, jak vám můžeme pomoci?', ago: 2000, message: ['app_id' => 1000000000000099]));
	$id = $question('2400000000000001', 'm_q1');

	$row = $action($id);
	Assert::same(['reply', 'pending', 'fb:2400000000000001'], [$row->type, $row->status, $row->external_target_id]);
	Assert::notNull($row->message_id);
	Assert::null($row->comment_id);
	$last = end($client->requests) ?: throw new LogicException('No request.');
	$input = Json::decode($last['input'], forceArrays: true);
	Assert::same('message', $input['kind']);
	Assert::same([
		['from' => 'customer', 'text' => 'Dobrý den, mám dotaz.'],
		['from' => 'business', 'text' => 'Dobrý den, jak vám můžeme pomoci?'],
	], $input['history']);

	$actions->approve($id, 1);
	$dispatcher->send($action($id)->outbox_id, lastAttempt: false);
	Assert::same([
		'platform' => Platform::Facebook,
		'token' => 'synthetic-page-token',
		'sender' => 'fb:1000000000000001',
		'recipient' => 'fb:2400000000000001',
		'message' => 'Online předplatné stojí 289 Kč měsíčně.',
		'metadata' => "reply:$id",
	], $meta->writes[0]);
	Assert::same(['sent', ['reply_id' => 'fb:m_sent_1']], [$action($id)->status, Json::decode((string) $action($id)->result_json, forceArrays: true)]);

	$conversation = $conversationOf('2400000000000001');
	Assert::same(1, $inbox->count(InboxFilter::Open));
	Assert::same(0, $inbox->count(InboxFilter::Pending));
	Assert::same([$conversation], array_map(fn($row) => (int) $row->id, $inbox->page(null, 10, 0)));
	Assert::same(['incoming', 'outgoing', 'incoming'], array_column($inbox->messages($conversation), 'direction'));
});


test('the window is checked at the moment of sending', function () use ($question, $action, $actions, $dispatcher, $meta, $reasons, $database, $conversationOf): void {
	$id = $question('2400000000000002', 'm_q2');
	$actions->approve($id, 1);
	$database->query('UPDATE social_conversations SET window_expires_at = NOW(3) - INTERVAL 1 SECOND WHERE id = ?', $conversationOf('2400000000000002'));

	$dispatcher->send($action($id)->outbox_id, lastAttempt: false);
	Assert::same(['window_expired'], $reasons($id));
	Assert::count(1, $meta->writes);

	$own = $actions->message($conversationOf('2400000000000002'), 'Text from the operator', 1);
	$dispatcher->send($action($own)->outbox_id, lastAttempt: false);
	Assert::same(['window_expired'], $reasons($own), 'An operator message is checked as well.');
});


test('an echo after the message means it was answered; an unsent message is not answered', function () use ($question, $receive, $echo, $action, $actions, $dispatcher, $meta, $reasons): void {
	$id = $question('2400000000000003', 'm_q3');
	$actions->approve($id, 1);
	$receive($echo('2400000000000003', 'm_human', 'Odpověděl člověk v Business Suite.'));
	$dispatcher->send($action($id)->outbox_id, lastAttempt: false);
	Assert::same(['human_takeover', 'already_replied'], $reasons($id), 'A person answered outside the application.');

	$id = $question('2400000000000004', 'm_q4');
	$actions->approve($id, 1);
	$receive([
		'sender' => ['id' => '2400000000000004'],
		'recipient' => ['id' => '1000000000000001'],
		'timestamp' => (int) (microtime(true) * 1000),
		'message' => ['mid' => 'm_q4', 'is_deleted' => true],
	]);
	$dispatcher->send($action($id)->outbox_id, lastAttempt: false);
	Assert::same(['message_deleted'], $reasons($id));
	Assert::count(1, $meta->writes);
});


test('takeover stops AI drafts and AI replies, the operator still writes; release restores', function () use ($question, $action, $actions, $dispatcher, $meta, $reasons, $conversations, $inbox, $conversationOf, $receive, $customer, $client, $decider, $drafter, $database): void {
	$id = $question('2400000000000005', 'm_q5');
	$conversation = $conversationOf('2400000000000005');
	$actions->approve($id, 1);
	$conversations->takeover($conversation, 1);
	Assert::true($conversations->isTakenOver($conversation));
	Assert::same(2, $inbox->count(InboxFilter::Takeover), 'Also the conversation answered in Business Suite.');
	Assert::same('Moderator', $inbox->find($conversation)?->assignee_name);

	$dispatcher->send($action($id)->outbox_id, lastAttempt: false);
	Assert::same(['human_takeover'], $reasons($id));

	// A new question during the takeover gets no draft.
	$eventId = $receive($customer('2400000000000005', 'm_q5b', 'A roční předplatné?'));
	$client->queue(FakeOpenAiClient::classification(['knowledge_required' => ['pricing_current']]));
	$decider->decide($eventId, lastAttempt: false);
	$draftless = (int) $database->query('SELECT id FROM moderation_actions WHERE event_id = ?', $eventId)->fetchField();
	$requests = count($client->requests);
	$drafter->draft($draftless, lastAttempt: false);
	Assert::same(['human_takeover'], $reasons($draftless));
	Assert::count($requests, $client->requests);

	// The operator answers by hand.
	Assert::exception(fn() => $actions->message($conversation, '   ', 1), InvalidAction::class, 'actions.error.text');
	$own = $actions->message($conversation, ' Roční předplatné zatím nenabízíme. ', 1);
	$dispatcher->send($action($own)->outbox_id, lastAttempt: false);
	Assert::same('sent', $action($own)->status);
	Assert::same('Roční předplatné zatím nenabízíme.', $meta->writes[1]['message'] ?? null);
	Assert::same("reply:$own", $meta->writes[1]['metadata'] ?? null, 'The idempotency key goes with the message.');

	$conversations->release($conversation, 1);
	Assert::false($conversations->isTakenOver($conversation));
	Assert::same(2, (int) $database->query(
		"SELECT COUNT(*) FROM admin_audit WHERE action IN ('conversation.takeover', 'conversation.release') AND target_id = ?",
		$conversation,
	)->fetchField());
});


test('observe mode: nothing can be approved or written', function () use ($question, $actions, $policies, $conversationOf): void {
	$id = $question('2400000000000006', 'm_q6');
	$policies->save(Mode::Observe, new PolicySettings(maxRepeats: null), 1);
	Assert::exception(fn() => $actions->approve($id, 1), InvalidAction::class, 'actions.error.observe');
	Assert::exception(fn() => $actions->message($conversationOf('2400000000000006'), 'Text', 1), InvalidAction::class, 'actions.error.observe');
});


test('a reply written outside the application takes the conversation over; own echoes do not', function () use ($receive, $customer, $echo, $conversations, $inbox, $conversationOf, $database, $meta): void {
	$psid = '2400000000000007';
	$receive($customer($psid, 'm_q7', 'Dotaz', ago: 10_000));
	$conversation = $conversationOf($psid);

	// The application's own messages: app ID, idempotency key as metadata, or the stored message ID.
	$receive($echo($psid, 'm_own_app', 'Own 1', ago: 9000, message: ['app_id' => 1000000000000099]));
	$key = (string) $database->query('SELECT idempotency_key FROM outbox ORDER BY id LIMIT 1')->fetchField();
	$receive($echo($psid, 'm_own_metadata', 'Own 2', ago: 8000, message: ['metadata' => $key]));
	$receive($echo($psid, 'm_sent_1', 'Own 3', ago: 7000));
	Assert::false($conversations->isTakenOver($conversation));

	// A person in Meta Business Suite (another app ID).
	$receive($echo($psid, 'm_person', 'Odpovídá člověk', ago: 6000, message: ['app_id' => 263902037430900]));
	Assert::true($conversations->isTakenOver($conversation));
	Assert::null($inbox->find($conversation)?->assignee_name);
	$audit = $database->query("SELECT operator_id, details FROM admin_audit WHERE action = 'conversation.takeover' AND target_id = ?", $conversation)->fetch();
	Assert::same([null, 'reply outside the application'], [$audit?->operator_id, $audit?->details]);

	// A repeated delivery of the same echo after a release changes nothing; a newer reply takes it over again.
	$conversations->release($conversation, 1);
	$receive($echo($psid, 'm_person', 'Odpovídá člověk', ago: 6000, message: ['app_id' => 263902037430900]));
	$receive($echo($psid, 'm_person_old', 'Starší odpověď doručená pozdě', ago: 5000));
	Assert::false($conversations->isTakenOver($conversation), 'A reply older than the release does not take it over.');
	$receive($echo($psid, 'm_person_new', 'Nová odpověď', ago: -5000));
	Assert::true($conversations->isTakenOver($conversation));
	Assert::count(2, $meta->writes, 'Nothing was sent.');
});
