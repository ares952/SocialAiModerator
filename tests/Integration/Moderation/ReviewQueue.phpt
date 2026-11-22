<?php

declare(strict_types=1);

use App\Audit\AuditLog;
use App\Moderation\ReviewFilter;
use App\Moderation\ReviewQueue;
use Tester\Assert;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

// Review queue (specification §4.3): which events wait for a human, resolving, handover. Synthetic
// rows only.

$database = TestDatabase::create();
$queue = new ReviewQueue($database, new AuditLog($database));
foreach (['moderator', 'viewer'] as $index => $role) {
	$database->query('INSERT INTO operators ?', [
		'email' => "$role@example.test",
		'name' => ucfirst($role),
		'role' => $role,
		'password_hash' => 'x',
		'active' => $role === 'moderator',
	]);
}
$moderator = (int) $database->query("SELECT id FROM operators WHERE role = 'moderator'")->fetchField();
$inactive = (int) $database->query("SELECT id FROM operators WHERE role = 'viewer'")->fetchField();
$database->query('INSERT INTO social_accounts ?', [
	'platform' => 'facebook',
	'external_id' => 'fb:1000000000000001',
	'name' => 'Test Page',
	'status' => 'active',
]);
$accountId = (int) $database->getInsertId();
$database->query('INSERT INTO social_comments ?', [
	'account_id' => $accountId,
	'external_id' => 'fb:c1',
	'visibility' => 'visible',
]);
$commentId = (int) $database->getInsertId();

// One event per case; returns its ID.
$event = static function (string $type, string $status, ?string $decision) use ($database, $accountId): int {
	$database->query('INSERT INTO social_events ?', [
		'platform' => 'facebook',
		'account_external_id' => 'fb:1000000000000001',
		'account_id' => $accountId,
		'event_key' => bin2hex(random_bytes(32)),
		'topic' => 'feed',
		'event_type' => $type,
		'payload_encrypted' => 'x',
		'status' => $status,
		'received_at' => new DateTimeImmutable,
	]);
	$id = (int) $database->getInsertId();
	if ($decision !== null) {
		$database->query('INSERT INTO moderation_decisions ?', [
			'event_id' => $id,
			'model' => 'model-a',
			'prompt_version' => 'classifier-v2',
			'policy_version' => 0,
			'category' => 'faq',
			'action' => $decision,
			'automatic' => false,
			'result_json' => '{"reasons":["model_requires_human"]}',
		]);
	}

	return $id;
};

$handoff = $event('comment.created', 'review', 'handoff');
$keep = $event('comment.created', 'review', 'keep');
$undecided = $event('message.received', 'review', null);
$reply = $event('comment.created', 'review', 'reply');
$database->query('INSERT INTO moderation_actions ?', [
	'comment_id' => $commentId,
	'event_id' => $reply,
	'type' => 'reply',
	'status' => 'pending',
	'automatic' => false,
	'external_target_id' => 'fb:c1',
]);
$ignored = $event('comment.created', 'ignored', null);
$removed = $event('comment.removed', 'review', null);


test('the queue holds handoffs, undecided events and waiting interventions, oldest first', function () use ($queue, $handoff, $undecided, $reply, $keep, $ignored, $removed): void {
	Assert::same(3, $queue->count(null, null));
	$items = $queue->page(null, null, 10, 0);
	Assert::same([$handoff, $undecided, $reply], array_map(fn($item): int => (int) $item->id, $items));
	Assert::same('handoff', $items[0]->decision_action);
	Assert::same('["model_requires_human"]', $items[0]->reasons_json);
	Assert::null($items[1]->decision_action);
	Assert::same(1, (int) $items[2]->pending);
	foreach ([$keep, $ignored, $removed] as $id) {
		Assert::false($queue->isOpen($id), "event $id");
	}
	Assert::same($undecided, $queue->next($handoff));
	Assert::same($handoff, $queue->next($reply), 'After the last one the queue starts over.');
});


test('resolving and reopening are audited; a decided intervention leaves the queue', function () use ($queue, $database, $handoff, $reply, $moderator): void {
	$queue->resolve($handoff, $moderator);
	$queue->resolve($handoff, $moderator);
	Assert::false($queue->isOpen($handoff));
	Assert::same(2, $queue->count(null, null));
	$queue->reopen($handoff, $moderator);
	Assert::true($queue->isOpen($handoff));
	Assert::same(['event.resolve', 'event.reopen'], $database->query(
		"SELECT action FROM admin_audit WHERE target_type = 'event' ORDER BY id",
	)->fetchPairs(null, 'action'));

	$database->query("UPDATE moderation_actions SET status = 'blocked', reasons_json = '[\"rejected\"]' WHERE event_id = ?", $reply);
	Assert::false($queue->isOpen($reply), 'The AI proposed a reply, the moderator rejected it: nothing left to do.');
});


test('handover to an active operator, filters by handover', function () use ($queue, $database, $handoff, $moderator, $inactive): void {
	$queue->assign($handoff, $moderator, $moderator);
	$queue->assign($handoff, $moderator, $moderator);
	Assert::same(1, $queue->count(ReviewFilter::Mine, $moderator));
	Assert::same(0, $queue->count(ReviewFilter::Mine, $inactive));
	Assert::same(1, $queue->count(ReviewFilter::Unassigned, $moderator));
	Assert::same(['assignee=' . $moderator], $database->query(
		"SELECT details FROM admin_audit WHERE action = 'event.assign'",
	)->fetchPairs(null, 'details'), 'An unchanged handover is not audited again.');
	Assert::exception(fn() => $queue->assign($handoff, $inactive, $moderator), InvalidArgumentException::class);

	$queue->assign($handoff, null, $moderator);
	Assert::same(2, $queue->count(ReviewFilter::Unassigned, $moderator));
});
