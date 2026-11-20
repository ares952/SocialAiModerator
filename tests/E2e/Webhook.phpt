<?php

declare(strict_types=1);

use Nette\Utils\Json;
use Tester\Assert;
use Tests\Support\E2eBrowser;
use Tests\Support\E2eData;

require __DIR__ . '/../bootstrap.php';

// Meta webhook through nginx, stored, queued and processed by the e2e worker (runs only in
// bin/e2e). Synthetic payloads only.

$browser = E2eBrowser::create();
$data = new E2eData;
$secret = (string) getenv('META_APP_SECRET');
$data->database->query('INSERT INTO social_accounts ?', [
	'platform' => 'facebook',
	'external_id' => 'fb:1000000000000042',
	'name' => 'E2E Webhook Page',
	'status' => 'active',
]);


test('subscription challenge needs the verify token', function () use ($browser): void {
	$token = rawurlencode((string) getenv('META_WEBHOOK_VERIFY_TOKEN'));
	$ok = $browser->get("/api/meta/webhook?hub.mode=subscribe&hub.verify_token=$token&hub.challenge=4242");
	Assert::same(200, $ok->status);
	Assert::same('4242', $ok->body);
	Assert::same(403, $browser->get('/api/meta/webhook?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=1')->status);
});


test('a signed event is stored and the worker moves it to review', function () use ($data, $secret): void {
	$body = Json::encode([
		'object' => 'page',
		'entry' => [
			[
				'id' => '1000000000000042',
				'time' => 1763640000,
				'changes' => [
					[
						'field' => 'feed',
						'value' => [
							'item' => 'comment',
							'verb' => 'add',
							'comment_id' => 'e2e_' . bin2hex(random_bytes(4)),
							'message' => 'Synthetic comment',
						],
					],
				],
			],
		],
	]);

	$post = static function (string $signature) use ($body): int {
		$curl = curl_init(getenv('E2E_BASE_URL') . '/api/meta/webhook');
		curl_setopt_array($curl, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				"X-Hub-Signature-256: $signature",
			],
		]);
		curl_exec($curl);

		return curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
	};

	Assert::same(403, $post('sha256=' . hash_hmac('sha256', $body, 'wrong')));
	Assert::same(200, $post('sha256=' . hash_hmac('sha256', $body, $secret)));
	Assert::same(200, $post('sha256=' . hash_hmac('sha256', $body, $secret)));

	$status = null;
	for ($i = 0; $i < 50 && $status !== 'review'; $i++) {
		usleep(200_000);
		$status = $data->database->query(
			"SELECT status FROM social_events WHERE account_external_id = 'fb:1000000000000042' ORDER BY id DESC LIMIT 1",
		)->fetchField();
	}
	Assert::same('review', $status);
	Assert::same(1, $data->database->query("SELECT COUNT(*) FROM social_events WHERE account_external_id = 'fb:1000000000000042'")->fetchField());
	Assert::same('done', $data->database->query("SELECT status FROM queue_jobs WHERE type = 'event.process' ORDER BY id DESC LIMIT 1")->fetchField());
});
