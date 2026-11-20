<?php

declare(strict_types=1);

use App\Core\Encryptor;
use App\Ingest\EventStore;
use App\Meta\WebhookVerifier;
use App\Queue\JobQueue;
use App\Presentation\Api\MetaWebhook\MetaWebhookPresenter;
use Nette\Application\Request as AppRequest;
use Nette\Application\Responses\TextResponse;
use Nette\Database\Explorer;
use Nette\Http\Request;
use Nette\Http\Response;
use Nette\Http\UrlScript;
use Nette\Utils\Json;
use Tester\Assert;
use Tests\Support\TestDatabase;
use Tests\Support\TestLog;

require __DIR__ . '/../../bootstrap.php';

// Webhook endpoint end to end with the test database (specification §3.3, §9.1). Synthetic
// payloads only.

const AppSecret = 'test-app-secret';
const VerifyToken = 'test-verify-token';

$database = TestDatabase::create();
$encryptor = new Encryptor(base64_encode(random_bytes(32)));
$queue = new JobQueue(
	$database,
	maxAttempts: 3,
	backoffSeconds: 30,
	backoffCapSeconds: 3600,
	leaseSeconds: 600,
);
$database->query('INSERT INTO social_accounts ?', [
	[
		'platform' => 'facebook',
		'external_id' => 'fb:1000000000000001',
		'name' => 'Test Page',
		'status' => 'active',
	],
	[
		'platform' => 'facebook',
		'external_id' => 'fb:1000000000000003',
		'name' => 'Disabled Page',
		'status' => 'disabled',
	],
]);


/**
 * @return array{int, string, list<string>}
 */
$call = static function (
	string $method,
	string $query = '',
	string $body = '',
	?string $signature = null,
) use ($database, $encryptor, $queue): array {
	$log = new TestLog('webhook-' . uniqid() . '.log');
	$httpResponse = new Response;
	$presenter = new MetaWebhookPresenter(
		new WebhookVerifier(AppSecret, VerifyToken),
		new EventStore($database, $encryptor, $queue),
		new Request(
			new UrlScript('https://moderator.test/api/meta/webhook' . $query, '/'),
			headers: $signature === null ? [] : ['X-Hub-Signature-256' => $signature],
			method: $method,
			rawBodyCallback: static fn(): string => $body,
		),
		$httpResponse,
		$log->logger,
	);

	$response = $presenter->run(new AppRequest('Api:MetaWebhook'));
	if (!$response instanceof TextResponse) {
		throw new LogicException('Expected a TextResponse, got ' . get_debug_type($response));
	}

	return [$httpResponse->getCode(), (string) $response->getSource(), $log->messages()];
};

$sign = static fn(string $body): string => 'sha256=' . hash_hmac('sha256', $body, AppSecret);

/**
 * @param array<mixed> $messaging
 */
$batch = static fn(string $pageId, array $changes, array $messaging = []): string => Json::encode([
	'object' => 'page',
	'entry' => [
		[
			'id' => $pageId,
			'time' => 1763640000,
			'changes' => $changes,
			'messaging' => $messaging,
		],
	],
]);

$comment = static fn(string $commentId, string $message): array => [
	'field' => 'feed',
	'value' => [
		'item' => 'comment',
		'verb' => 'add',
		'comment_id' => $commentId,
		'message' => $message,
	],
];

$events = static fn(Explorer $database): array => $database
	->query('SELECT account_external_id, account_id, topic, status FROM social_events ORDER BY id')
	->fetchAll();


test('subscription challenge is echoed only with the verify token', function () use ($call): void {
	[$code, $body] = $call('GET', '?hub.mode=subscribe&hub.verify_token=' . VerifyToken . '&hub.challenge=1158201444');
	Assert::same(200, $code);
	Assert::same('1158201444', $body);

	[$code, $body, $log] = $call('GET', '?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=1158201444');
	Assert::same(403, $code);
	Assert::same('', $body);
	Assert::same(['warning: Meta webhook subscription rejected: invalid verify token'], $log);
});


test('invalid signature is 403 and stores nothing', function () use ($call, $batch, $comment, $database): void {
	$body = $batch('1000000000000001', [$comment('c1', 'Synthetic')]);

	Assert::same(403, $call('POST', body: $body)[0]);
	Assert::same(403, $call('POST', body: $body, signature: 'sha256=' . hash_hmac('sha256', $body, 'other'))[0]);
	Assert::same(403, $call('POST', body: $body . ' ', signature: 'sha256=' . hash_hmac('sha256', $body, AppSecret))[0]);
	Assert::same(0, $database->table('social_events')->count('*'));
});


test('signed multi-entry webhook stores every event encrypted', function () use ($call, $batch, $comment, $sign, $database, $encryptor, $events): void {
	$body = $batch(
		'1000000000000001',
		[
			$comment('c1', 'Synthetic comment one'),
			$comment('c2', 'Synthetic comment two'),
		],
		[
			[
				'sender' => ['id' => '3000000000000001'],
				'timestamp' => 1763640000000,
				'message' => ['mid' => 'm_synthetic_1', 'text' => 'Synthetic direct message'],
			],
		],
	);

	[$code, $response] = $call('POST', body: $body, signature: $sign($body));
	Assert::same(200, $code);
	Assert::same('EVENT_RECEIVED', $response);

	$rows = $events($database);
	Assert::count(3, $rows);
	Assert::same(['feed', 'feed', 'messaging'], array_map(fn($row) => $row->topic, $rows));
	Assert::same(['received', 'received', 'received'], array_map(fn($row) => $row->status, $rows));
	Assert::notNull($rows[0]->account_id);
	Assert::same(
		3,
		$database->query("SELECT COUNT(*) FROM queue_jobs WHERE type = 'event.process' AND status = 'pending'")->fetchField(),
	);

	$stored = $database->query('SELECT payload_encrypted FROM social_events ORDER BY id LIMIT 1')->fetchField();
	Assert::type('string', $stored);
	Assert::notContains('Synthetic', $stored);
	$payload = Json::decode($encryptor->decrypt($stored, EventStore::PayloadContext), forceArrays: true);
	Assert::same('Synthetic comment one', $payload['item']['value']['message']);
});


test('redelivered and reordered events are stored once', function () use ($call, $batch, $comment, $sign, $database): void {
	$body = $batch('1000000000000001', [
		$comment('c2', 'Synthetic comment two'),
		$comment('c1', 'Synthetic comment one'),
		$comment('c3', 'Synthetic comment three'),
	]);

	Assert::same(200, $call('POST', body: $body, signature: $sign($body))[0]);
	Assert::same(200, $call('POST', body: $body, signature: $sign($body))[0]);
	Assert::same(4, $database->table('social_events')->count('*'));
	Assert::same(4, $database->table('queue_jobs')->count('*'));
});


test('events of unknown or disabled accounts are kept as ignored', function () use ($call, $batch, $comment, $sign, $database): void {
	$database->query('DELETE FROM social_events');
	$database->query('DELETE FROM queue_jobs');
	foreach (['1000000000000002', '1000000000000003'] as $pageId) {
		$body = $batch($pageId, [$comment('c1', 'Synthetic')]);
		Assert::same(200, $call('POST', body: $body, signature: $sign($body))[0]);
	}

	$rows = $database->query('SELECT account_external_id, account_id, status FROM social_events ORDER BY id')->fetchAll();
	Assert::same('fb:1000000000000002', $rows[0]->account_external_id);
	Assert::null($rows[0]->account_id);
	Assert::same('ignored', $rows[0]->status);
	Assert::notNull($rows[1]->account_id);
	Assert::same('ignored', $rows[1]->status);
	Assert::same(0, $database->table('queue_jobs')->count('*'));
});


test('same event key of another account is a different event', function () use ($call, $batch, $comment, $sign, $database): void {
	$database->query('DELETE FROM social_events');
	foreach (['1000000000000001', '1000000000000002'] as $pageId) {
		$body = $batch($pageId, [$comment('c1', 'Synthetic')]);
		$call('POST', body: $body, signature: $sign($body));
	}

	Assert::same(2, $database->table('social_events')->count('*'));
});


test('signed but malformed body is 400 without customer content in the log', function () use ($call, $sign, $database): void {
	$database->query('DELETE FROM social_events');
	$body = '{"object":"page","entry":[{"id":"1","changes":[{"value":{"message":"Synthetic secret"}}]}]}';

	[$code, , $log] = $call('POST', body: $body, signature: $sign($body));
	Assert::same(400, $code);
	Assert::same(['error: Meta webhook rejected: Change item must have a `field`.'], $log);
	Assert::same(0, $database->table('social_events')->count('*'));
});


test('other methods are not allowed', function () use ($call): void {
	Assert::same(405, $call('PUT')[0]);
});
