<?php

declare(strict_types=1);

use App\Account\Credentials;
use App\Account\TokenType;
use App\Audit\AuditLog;
use App\Core\Encryptor;
use App\Ingest\WebhookBatch;
use App\Meta\MetaFailure;
use App\Meta\Platform;
use App\Meta\UnavailableMetaClient;
use App\Moderation\AutomationSwitch;
use App\Moderation\Mode;
use App\Moderation\Policies;
use App\Moderation\PolicySettings;
use App\Outbox\ActionType;
use App\Outbox\InvalidAction;
use App\Queue\JobQueue;
use App\Queue\Postponed;
use Nette\Utils\Json;
use Tester\Assert;
use Tests\Fakes\FakeMetaClient;
use Tests\Fakes\FakeOpenAiClient;
use Tests\Support\TestDatabase;
use Tests\Support\TestLog;
use Tests\Support\TestServices;

require __DIR__ . '/../../bootstrap.php';

// Moderation actions -> outbox -> ActionDispatcher (safety invariants 2, 3, 9 and 12 of AGENTS.md):
// nothing reaches Meta in observe mode or during a pause, every send is re-validated at send time,
// an uncertain result becomes unknown and is never repeated. Fake Meta and OpenAI only.

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
	->replace($accountId, 'synthetic-page-token', TokenType::Page, ['pages_manage_engagement'], null, null, null);

$policies = TestServices::policies($database);
$mode = static fn(Mode $mode, bool $autoHide = false) => $policies->save($mode, new PolicySettings(autoHide: $autoHide), 1);
$switch = new AutomationSwitch($database, new AuditLog($database));
$client = new FakeOpenAiClient;
$decider = TestServices::eventDecider($database, $encryptor, $queue, $client);
$actions = TestServices::actions($database, $encryptor, $queue);
$meta = new FakeMetaClient;
$log = new TestLog;
$dispatcher = TestServices::dispatcher($database, $encryptor, $meta, $log->logger);

// A spam comment decided as hide; returns the event ID.
$spam = static function (string $commentId) use ($database, $encryptor, $queue, $client, $decider): int {
	TestServices::eventStore($database, $encryptor, $queue)->save(WebhookBatch::parse(Json::encode([
		'object' => 'page',
		'entry' => [
			[
				'id' => '1000000000000001',
				'time' => 1763640000,
				'changes' => [
					[
						'field' => 'feed',
						'value' => [
							'item' => 'comment',
							'verb' => 'add',
							'comment_id' => $commentId,
							'created_time' => 1763640000,
							'message' => 'Synthetic spam: cheap followers at spam.example',
							'from' => [
								'id' => '2100000000000001',
								'name' => 'Synthetic Spammer',
							],
						],
					],
				],
			],
		],
	])));
	$id = (int) $database->query('SELECT MAX(id) FROM social_events')->fetchField();
	TestServices::eventProcessor($database, $encryptor, $queue)->process($id);
	$client->queue(FakeOpenAiClient::classification([
		'category' => 'spam',
		'recommended_action' => 'hide',
		'confidence' => 0.99,
		'spam_probability' => 0.99,
	]));
	$decider->decide($id, lastAttempt: false);

	return $id;
};
$action = static fn(int $eventId) => $database->query(
	'SELECT a.id, a.status, a.automatic, a.reasons_json, a.reviewed_by, a.executed_at, a.result_json,
		x.id AS outbox_id, x.status AS outbox_status, x.idempotency_key, x.attempts, c.visibility
	FROM moderation_actions a
	JOIN social_comments c ON c.id = a.comment_id
	LEFT JOIN outbox x ON x.action_id = a.id
	WHERE a.event_id = ?
	ORDER BY a.id DESC LIMIT 1',
	$eventId,
)->fetch() ?? throw new LogicException('No action.');
$reasons = static fn(int $eventId): array => Json::decode((string) $action($eventId)->reasons_json, forceArrays: true);
$sendJobs = static fn(int $outboxId): int => (int) $database->query(
	"SELECT COUNT(*) FROM queue_jobs WHERE type = 'action.send' AND subject_id = ?",
	$outboxId,
)->fetchField();


test('observe mode: a hide waits for a human, cannot be approved, only rejected', function () use ($spam, $action, $actions, $reasons): void {
	$id = $spam('c1');
	$row = $action($id);
	Assert::same(['pending', 0, null], [$row->status, $row->automatic, $row->outbox_id]);

	Assert::exception(fn() => $actions->approve($row->id, 1), InvalidAction::class, 'actions.error.observe');
	Assert::exception(fn() => $actions->request(1, $id, ActionType::Hide, 1), InvalidAction::class, 'actions.error.observe');

	$actions->reject($row->id, 1);
	Assert::same(['blocked', 1, null], [$action($id)->status, $action($id)->reviewed_by, $action($id)->outbox_id]);
	Assert::same(['rejected'], $reasons($id));
	Assert::exception(fn() => $actions->approve($row->id, 1), InvalidAction::class, 'actions.error.observe');
});


test('assisted mode: an approved hide is sent once and the comment becomes hidden', function () use ($spam, $action, $actions, $mode, $dispatcher, $meta, $sendJobs, $database): void {
	$mode(Mode::Assisted);
	$id = $spam('c2');
	$row = $action($id);
	Assert::same('pending', $row->status);

	$actions->approve($row->id, 1);
	$row = $action($id);
	Assert::same(['approved', 'pending', "hide:$row->id"], [$row->status, $row->outbox_status, $row->idempotency_key]);
	Assert::same(1, $sendJobs($row->outbox_id));
	Assert::same(1, (int) $database->query("SELECT COUNT(*) FROM admin_audit WHERE action = 'action.approve' AND target_id = ?", $row->id)->fetchField());
	Assert::exception(fn() => $actions->approve($row->id, 1), InvalidAction::class, 'actions.error.not-pending');

	$dispatcher->send($row->outbox_id, lastAttempt: false);
	Assert::same([
		[
			'platform' => Platform::Facebook,
			'token' => 'synthetic-page-token',
			'comment' => 'fb:c2',
			'hidden' => true,
		],
	], $meta->writes);
	$row = $action($id);
	Assert::same(['sent', 'sent', 1, 'hidden'], [$row->status, $row->outbox_status, $row->attempts, $row->visibility]);
	Assert::notNull($row->executed_at);

	$dispatcher->send($row->outbox_id, lastAttempt: false);
	Assert::count(1, $meta->writes, 'A sent record is never sent again.');
});


test('an operator unhides the comment; a second open action is refused', function () use ($actions, $dispatcher, $meta, $database): void {
	$commentId = (int) $database->query("SELECT id FROM social_comments WHERE external_id = 'fb:c2'")->fetchField();
	Assert::exception(fn() => $actions->request($commentId, null, ActionType::Hide, 1), InvalidAction::class, 'actions.error.state');

	$actionId = $actions->request($commentId, null, ActionType::Unhide, 1);
	Assert::exception(fn() => $actions->request($commentId, null, ActionType::Unhide, 1), InvalidAction::class, 'actions.error.open');
	$outboxId = (int) $database->query('SELECT id FROM outbox WHERE action_id = ?', $actionId)->fetchField();
	$dispatcher->send($outboxId, lastAttempt: false);

	Assert::false($meta->writes[1]['hidden'] ?? null);
	Assert::same('visible', $database->query('SELECT visibility FROM social_comments WHERE id = ?', $commentId)->fetchField());
	Assert::same('unhide', $database->query("SELECT details FROM admin_audit WHERE action = 'action.request' AND target_id = ?", $actionId)->fetchField());
});


test('automatic mode with auto hide queues the action without a human', function () use ($spam, $action, $mode, $dispatcher, $meta): void {
	$mode(Mode::Automatic, autoHide: true);
	$id = $spam('c3');
	$row = $action($id);
	Assert::same(['approved', 1, 'pending'], [$row->status, $row->automatic, $row->outbox_status]);

	$dispatcher->send($row->outbox_id, lastAttempt: false);
	Assert::same('sent', $action($id)->status);
	Assert::same('fb:c3', $meta->writes[2]['comment']);
});


test('the panic switch holds even approved actions until it is turned off', function () use ($spam, $action, $actions, $mode, $switch, $dispatcher, $meta): void {
	$mode(Mode::Assisted);
	$id = $spam('c4');
	$actions->approve($action($id)->id, 1);
	$switch->pause(null, 'synthetic incident', 1);

	$outboxId = $action($id)->outbox_id;
	$e = Assert::exception(fn() => $dispatcher->send($outboxId, lastAttempt: false), Postponed::class);
	Assert::same(60, $e?->retryAfterSeconds);
	Assert::same(['approved', 'pending'], [$action($id)->status, $action($id)->outbox_status]);
	Assert::count(3, $meta->writes);

	$switch->resume(null, 1);
	$dispatcher->send($outboxId, lastAttempt: false);
	Assert::same('sent', $action($id)->status);
	Assert::count(4, $meta->writes);
});


test('the state at send time wins: observe mode, removed or edited comment, missing token', function () use ($spam, $action, $actions, $mode, $dispatcher, $meta, $reasons, $database, $accountId): void {
	$mode(Mode::Assisted);
	$id = $spam('c5');
	$actions->approve($action($id)->id, 1);
	$mode(Mode::Observe);
	$dispatcher->send($action($id)->outbox_id, lastAttempt: false);
	Assert::same(['blocked', 'blocked'], [$action($id)->status, $action($id)->outbox_status]);
	Assert::same(['mode_observe'], $reasons($id));

	$mode(Mode::Assisted);
	$id = $spam('c6');
	$actions->approve($action($id)->id, 1);
	$database->query("UPDATE social_comments SET visibility = 'removed' WHERE external_id = 'fb:c6'");
	$dispatcher->send($action($id)->outbox_id, lastAttempt: false);
	Assert::same(['comment_removed'], $reasons($id));

	$mode(Mode::Automatic, autoHide: true);
	$id = $spam('c7');
	$database->query("UPDATE social_comments SET text_changed_at = NOW(3) + INTERVAL 1 SECOND WHERE external_id = 'fb:c7'");
	$dispatcher->send($action($id)->outbox_id, lastAttempt: false);
	Assert::same(['comment_changed'], $reasons($id));

	$id = $spam('c8');
	$database->query("UPDATE account_credentials SET status = 'invalid' WHERE social_account_id = ?", $accountId);
	$dispatcher->send($action($id)->outbox_id, lastAttempt: false);
	Assert::same(['token_unavailable'], $reasons($id));
	$database->query("UPDATE account_credentials SET status = 'unchecked' WHERE social_account_id = ?", $accountId);

	Assert::count(4, $meta->writes, 'Nothing was sent.');
});


test('an uncertain result becomes unknown and is never repeated', function () use ($spam, $action, $dispatcher, $meta, $log): void {
	$id = $spam('c9');
	$meta->fail(new MetaFailure('Timeout after sending', uncertain: true, transient: true));
	$dispatcher->send($action($id)->outbox_id, lastAttempt: false);
	$row = $action($id);
	Assert::same(['unknown', 'unknown', 'visible'], [$row->status, $row->outbox_status, $row->visibility]);

	$dispatcher->send($row->outbox_id, lastAttempt: false);
	Assert::count(5, $meta->writes);
	Assert::contains('warning: Action result unknown', $log->messages());
});


test('an interrupted send is unknown, not repeated', function () use ($spam, $action, $dispatcher, $meta, $database): void {
	$id = $spam('c10');
	$database->query("UPDATE outbox SET status = 'sending' WHERE id = ?", $action($id)->outbox_id);
	$dispatcher->send($action($id)->outbox_id, lastAttempt: false);
	Assert::same(['unknown', 'unknown'], [$action($id)->status, $action($id)->outbox_status]);
	Assert::count(5, $meta->writes);
});


test('a failure that changed nothing is retried by the queue, then fails', function () use ($spam, $action, $dispatcher, $meta): void {
	$id = $spam('c11');
	$outboxId = $action($id)->outbox_id;
	$meta->fail(new MetaFailure('HTTP 429', uncertain: false, transient: true, httpStatus: 429, errorCode: 4));
	Assert::exception(fn() => $dispatcher->send($outboxId, lastAttempt: false), MetaFailure::class);
	Assert::same(['approved', 'pending'], [$action($id)->status, $action($id)->outbox_status]);

	$meta->fail(new MetaFailure('HTTP 429', uncertain: false, transient: true, httpStatus: 429, errorCode: 4));
	$dispatcher->send($outboxId, lastAttempt: true);
	$row = $action($id);
	Assert::same(['failed', 'failed', 2], [$row->status, $row->outbox_status, $row->attempts]);
	Assert::same([
		'http_status' => 429,
		'error_code' => 4,
	], Json::decode((string) $row->result_json, forceArrays: true));

	$id = $spam('c12');
	$meta->fail(new MetaFailure('HTTP 400', uncertain: false, transient: false, httpStatus: 400));
	$dispatcher->send($action($id)->outbox_id, lastAttempt: false);
	Assert::same('failed', $action($id)->status);
});


test('without a connected Meta client an action is blocked and nothing is sent', function () use ($spam, $action, $reasons, $database, $encryptor, $log): void {
	$id = $spam('c13');
	TestServices::dispatcher($database, $encryptor, new UnavailableMetaClient, $log->logger)->send($action($id)->outbox_id, lastAttempt: false);
	Assert::same(['blocked', 'blocked'], [$action($id)->status, $action($id)->outbox_status]);
	Assert::same(['meta_write_unavailable'], $reasons($id));
});
