<?php

declare(strict_types=1);

use App\Audit\AuditLog;
use App\Console\RetentionRunCommand;
use App\Retention\Retention;
use Symfony\Component\Console\Tester\CommandTester;
use Tester\Assert;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

// Retention periods: nothing is deleted without a period; with one, old events of that kind go
// with their decisions and jobs, newer ones and other kinds stay (specification §8.3).

$database = TestDatabase::create();
$retention = new Retention($database, new AuditLog($database));
$database->query("INSERT INTO operators (email, name, password_hash, role) VALUES ('admin@example.test', 'Admin', 'x', 'admin')");
$operatorId = (int) $database->getInsertId();

$event = static function (?string $type, int $ageDays) use ($database): int {
	$database->query('INSERT INTO social_events ?', [
		'platform' => 'facebook',
		'account_external_id' => 'fb:1000000000000001',
		'event_key' => hash('sha256', random_bytes(16)),
		'topic' => 'feed',
		'event_type' => $type,
		'payload_encrypted' => 'x',
		'status' => 'review',
		'received_at' => new DateTimeImmutable("-$ageDays days"),
	]);
	$id = (int) $database->getInsertId();
	$database->query("INSERT INTO queue_jobs (type, subject_id, status, available_at) VALUES ('event.process', ?, 'done', NOW())", $id);
	$database->query('INSERT INTO moderation_decisions ?', [
		'event_id' => $id,
		'model' => 'test-model',
		'prompt_version' => 'classifier-v1',
		'policy_version' => 0,
		'action' => 'review',
		'automatic' => 0,
		'result_json' => '{}',
	]);

	return $id;
};

$oldComment = $event('comment.created', 200);
$newComment = $event('comment.edited', 10);
$oldMessage = $event('message.received', 200);
$oldEcho = $event('message.echo', 200);
$oldOther = $event('other', 200);
$oldUnnormalized = $event(null, 200);
$database->query('INSERT INTO admin_audit ?', [
	'action' => 'login',
	'target_type' => 'operator',
	'created_at' => new DateTimeImmutable('-400 days'),
]);

$remaining = static fn (): array => $database->query('SELECT id FROM social_events ORDER BY id')->fetchPairs(null, 'id');


test('without periods nothing is deleted', function () use ($retention, $remaining): void {
	Assert::same([
		'comments' => null,
		'messages' => null,
		'other_events' => null,
		'audit' => null,
	], $retention->periods());
	Assert::same([], $retention->purge());
	Assert::count(6, $remaining());

	$command = new CommandTester(new RetentionRunCommand($retention));
	Assert::same(0, $command->execute([]));
	Assert::same("No retention period is set; nothing deleted.\n", $command->getDisplay());
});


test('a period deletes only older data of its kind, with decisions and jobs', function () use ($retention, $operatorId, $remaining, $database, $oldComment, $newComment, $oldMessage, $oldEcho, $oldOther, $oldUnnormalized): void {
	$retention->save([
		'comments' => 180,
		'messages' => null,
	], $operatorId);
	Assert::same(['comments' => 180, 'messages' => null, 'other_events' => null, 'audit' => null], $retention->periods());
	Assert::same('comments=180, messages=keep, other_events=keep, audit=keep', $database->query("SELECT details FROM admin_audit WHERE action = 'retention.update'")->fetchField());

	Assert::same(['comments' => 1], $retention->purge());
	Assert::same([$newComment, $oldMessage, $oldEcho, $oldOther, $oldUnnormalized], $remaining());
	Assert::same(0, $database->query('SELECT COUNT(*) FROM moderation_decisions WHERE event_id = ?', $oldComment)->fetchField());
	Assert::same(0, $database->query('SELECT COUNT(*) FROM queue_jobs WHERE subject_id = ?', $oldComment)->fetchField());

	$retention->save([
		'comments' => 180,
		'messages' => 180,
		'other_events' => 180,
		'audit' => 365,
	], $operatorId);
	$command = new CommandTester(new RetentionRunCommand($retention));
	Assert::same(0, $command->execute([]));
	Assert::same("comments: 0 deleted\nmessages: 2 deleted\nother_events: 2 deleted\naudit: 1 deleted\n", $command->getDisplay());
	Assert::same([$newComment], $remaining());
	Assert::same(1, $database->query('SELECT COUNT(*) FROM moderation_decisions')->fetchField());
	Assert::same(0, $database->query("SELECT COUNT(*) FROM admin_audit WHERE action = 'login'")->fetchField());
	Assert::same(2, $database->query("SELECT COUNT(*) FROM admin_audit WHERE action = 'retention.update'")->fetchField(), 'Recent audit stays.');
});


test('a period out of range is rejected', function () use ($retention, $operatorId): void {
	Assert::exception(fn() => $retention->save(['comments' => 0], $operatorId), InvalidArgumentException::class);
	Assert::exception(fn() => $retention->save(['audit' => Retention::MaxDays + 1], $operatorId), InvalidArgumentException::class);
	Assert::same(180, $retention->periods()['comments'], 'A rejected save changes nothing.');
});
