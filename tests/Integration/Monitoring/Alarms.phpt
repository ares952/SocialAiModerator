<?php

declare(strict_types=1);

use App\Account\Credentials;
use App\Account\TokenType;
use App\Audit\AuditLog;
use App\Console\MonitorCheckCommand;
use App\Core\Encryptor;
use App\Monitoring\Alarm;
use App\Monitoring\Alarms;
use App\Monitoring\AlarmThresholds;
use App\Monitoring\Metrics;
use App\Moderation\Mode;
use App\Moderation\PolicySettings;
use Symfony\Component\Console\Tester\CommandTester;
use Tester\Assert;
use Tests\Support\TestDatabase;
use Tests\Support\TestServices;

require __DIR__ . '/../../bootstrap.php';

// Alarms and dashboard numbers (specification §4.3, §8.4) with the seeded thresholds.
// Synthetic data only.

$database = TestDatabase::create();
$encryptor = new Encryptor(base64_encode(random_bytes(32)));
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
$accountId = (int) $database->getInsertId();

$heartbeat = TEMP_DIR . '/alarms.heartbeat';
touch($heartbeat);
$thresholds = new AlarmThresholds($database, new AuditLog($database));
$policies = TestServices::policies($database);
$alarms = new Alarms($heartbeat, $database, $thresholds, $policies);
$codes = static fn(): array => array_map(fn(Alarm $alarm) => $alarm->describe(), $alarms->current());


test('the seeded thresholds are in place; a quiet installation has only the missing token', function () use ($thresholds, $codes): void {
	Assert::same([
		'token_expiry' => 7,
		'webhook_silence' => 48,
		'queue_lag' => 15,
		'failures' => 3,
		'hidden_comments' => 20,
		'stale_approvals' => 24,
	], $thresholds->all());
	Assert::same(['token_missing (account=Test Page)'], $codes());
});


test('token problems of active accounts', function () use ($database, $encryptor, $accountId, $codes): void {
	(new Credentials($database, $encryptor, new AuditLog($database)))
		->replace($accountId, 'synthetic-token', TokenType::Page, ['pages_messaging'], new DateTimeImmutable('+3 days +1 hour'), null, null);
	Assert::same(['token_expiring (account=Test Page, days=3)'], $codes());

	$database->query("UPDATE account_credentials SET status = 'invalid'");
	Assert::same(['token_invalid (account=Test Page)'], $codes());
	$database->query("UPDATE account_credentials SET status = 'valid', expires_at = NOW() - INTERVAL 1 HOUR");
	Assert::same(['token_expired (account=Test Page)'], $codes());
	$database->query("UPDATE account_credentials SET expires_at = NULL, data_access_expires_at = NOW() + INTERVAL 2 DAY + INTERVAL 1 HOUR");
	Assert::same(['data_access_expiring (account=Test Page, days=2)'], $codes());
	$database->query('UPDATE account_credentials SET data_access_expires_at = NULL');
	Assert::same([], $codes());
});


test('silent webhooks, a stuck queue and a silent worker', function () use ($database, $accountId, $heartbeat, $codes): void {
	$database->query('INSERT INTO social_events ?', [
		'platform' => 'facebook',
		'account_external_id' => 'fb:1000000000000001',
		'account_id' => $accountId,
		'event_key' => str_repeat('a', 64),
		'topic' => 'feed',
		'payload_encrypted' => 'x',
		'status' => 'review',
		'received_at' => new Nette\Database\SqlLiteral('NOW() - INTERVAL 50 HOUR'),
	]);
	$database->query('INSERT INTO queue_jobs ?', [
		'type' => 'event.process',
		'subject_id' => 1,
		'status' => 'pending',
		'available_at' => new Nette\Database\SqlLiteral('NOW(3) - INTERVAL 20 MINUTE'),
	]);
	touch($heartbeat, time() - 30 * 60);
	Assert::same([
		'webhook_silence (account=Test Page, hours=50)',
		'queue_lag (minutes=20)',
		'worker_silent (minutes=30)',
	], $codes());

	unlink($heartbeat);
	Assert::contains('worker_missing', $codes());
	touch($heartbeat);
	$database->query('DELETE FROM queue_jobs');
	$database->query('UPDATE social_events SET received_at = NOW()');
	Assert::same([], $codes());
});


test('failures, open breakers, unknown results, many hides and stale approvals', function () use ($database, $thresholds, $policies, $codes): void {
	foreach (range(1, 3) as $i) {
		$database->query('INSERT INTO queue_jobs ?', [
			'type' => 'event.decide',
			'subject_id' => $i,
			'status' => 'failed',
			'available_at' => new Nette\Database\SqlLiteral('NOW(3)'),
		]);
	}
	$database->query("INSERT INTO circuit_breakers (name, failures, opened_until) VALUES ('openai', 5, NOW(3) + INTERVAL 1 MINUTE)");
	Assert::same(['circuit_open (api=openai)', 'failed_jobs (count=3)'], $codes());

	$database->query("INSERT INTO social_comments (account_id, external_id, visibility) VALUES (1, 'fb:c1', 'visible')");
	foreach (['unknown', 'pending', 'pending'] as $status) {
		$database->query('INSERT INTO moderation_actions ?', [
			'comment_id' => 1,
			'type' => 'hide',
			'status' => $status,
			'automatic' => false,
			'external_target_id' => 'fb:c1',
			'created_at' => new Nette\Database\SqlLiteral('NOW(3) - INTERVAL 30 HOUR'),
		]);
	}
	$database->query("INSERT INTO moderation_actions (comment_id, type, status, automatic, external_target_id) VALUES (1, 'hide', 'pending', 0, 'fb:c1')");
	$thresholds->save([
		'token_expiry' => 7,
		'webhook_silence' => 48,
		'queue_lag' => 15,
		'failures' => null,
		'hidden_comments' => 1,
		'stale_approvals' => 24,
	], 1);
	Assert::same([
		'circuit_open (api=openai)',
		'unknown_actions (count=1)',
		'hidden_comments (count=1)',
	], $codes(), 'Observe mode: waiting approvals are expected; failures are off.');

	$policies->save(Mode::Assisted, new PolicySettings, 1);
	Assert::contains('stale_approvals (count=2, hours=24)', $codes());
	Assert::same('token_expiry=7, webhook_silence=48, queue_lag=15, failures=off, hidden_comments=1, stale_approvals=24', $database->query(
		"SELECT details FROM admin_audit WHERE action = 'alarms.update'",
	)->fetchField());
	Assert::exception(fn() => $thresholds->save(['queue_lag' => 0], 1), InvalidArgumentException::class);
});


test('monitor:check exits with 1 while there is an alarm', function () use ($alarms, $database): void {
	$tester = new CommandTester(new MonitorCheckCommand($alarms));
	Assert::same(1, $tester->execute([]));
	Assert::contains('ALARM: circuit_open (api=openai)', $tester->getDisplay());

	$database->query('DELETE FROM circuit_breakers');
	$database->query('DELETE FROM moderation_actions');
	Assert::same(0, $tester->execute([]));
	Assert::same("OK: no alarms\n", $tester->getDisplay());
});


test('dashboard numbers and token usage per model', function () use ($database, $accountId): void {
	$eventId = (int) $database->query('SELECT id FROM social_events LIMIT 1')->fetchField();
	$database->query("UPDATE social_events SET event_type = 'comment.created' WHERE id = ?", $eventId);
	$database->query('INSERT INTO moderation_decisions ?', [
		'event_id' => $eventId,
		'model' => 'model-a',
		'prompt_version' => 'classifier-v2',
		'policy_version' => 0,
		'category' => 'faq',
		'action' => 'handoff',
		'automatic' => false,
		'result_json' => '{}',
		'input_tokens' => 100,
		'output_tokens' => 20,
	]);
	$database->query("INSERT INTO moderation_actions (comment_id, type, status, automatic, external_target_id, reasons_json, model, input_tokens, output_tokens)
		VALUES (1, 'reply', 'blocked', 0, 'fb:c1', '[\"rejected\"]', 'model-b', 300, 40), (1, 'hide', 'blocked', 0, 'fb:c1', '[\"mode_observe\"]', NULL, NULL, NULL)");

	$metrics = new Metrics($database);
	$day = $metrics->summary(24);
	Assert::same([1, 1, 0, 1, 1, 0], [$day['events'], $day['comments'], $day['messages'], $day['decisions'], $day['escalations'], $day['unclassified']]);
	Assert::same([1, 0, 1, 1], [$day['hides_proposed'], $day['hides_sent'], $day['rejected'], $day['blocked']]);
	Assert::same([
		['model' => 'model-a', 'calls' => 1, 'input' => 100, 'output' => 20],
		['model' => 'model-b', 'calls' => 1, 'input' => 300, 'output' => 40],
	], $metrics->tokens(24));
	Assert::same($accountId, (int) $database->query('SELECT account_id FROM social_events WHERE id = ?', $eventId)->fetchField());
});
