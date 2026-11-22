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
	foreach (['/webhooks/meta', '/api/meta/webhook'] as $path) {
		$ok = $browser->get("$path?hub.mode=subscribe&hub.verify_token=$token&hub.challenge=4242");
		Assert::same(200, $ok->status);
		Assert::same('4242', $ok->body);
		Assert::same(403, $browser->get("$path?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=1")->status);
	}
});


test('a signed event is stored and the worker normalizes it and moves it to review', function () use ($browser, $data, $secret): void {
	$commentId = 'e2e_' . bin2hex(random_bytes(4));
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
							'comment_id' => $commentId,
							'created_time' => 1763640000,
							'message' => 'Synthetic comment',
							'from' => ['id' => '2100000000000042', 'name' => 'Synthetic Customer'],
						],
					],
				],
			],
		],
	]);

	$post = static function (string $signature) use ($body): int {
		$curl = curl_init(getenv('E2E_BASE_URL') . '/webhooks/meta');
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
	$event = $data->database->query(
		"SELECT event_type, external_id FROM social_events WHERE account_external_id = 'fb:1000000000000042' ORDER BY id DESC LIMIT 1",
	)->fetch() ?? throw new LogicException;
	Assert::same(['comment.created', "fb:$commentId"], [$event->event_type, $event->external_id]);

	[$email, $password] = $data->operator('viewer');
	Assert::same(303, $browser->signIn($email, $password)->status);
	$page = $browser->get('/admin/events?type=comment.created');
	Assert::same(200, $page->status);
	Assert::contains('E2E Webhook Page', $page->body);
	Assert::contains('Nový komentář', $page->body);
	Assert::contains("fb:$commentId", $page->body);
	Assert::contains('událost 20. 11. 2025', $page->body, 'Meta time differs from the receipt time.');

	// Without OPENAI_CLASSIFIER_MODEL the AI decision fails closed before any HTTP call.
	$eventId = (int) $data->database->query(
		"SELECT id FROM social_events WHERE external_id = ?",
		"fb:$commentId",
	)->fetchField();
	$decision = null;
	for ($i = 0; $i < 50 && $decision === null; $i++) {
		usleep(200_000);
		$decision = $data->database->query('SELECT action, error FROM moderation_decisions WHERE event_id = ?', $eventId)->fetch();
	}
	Assert::same('review', $decision?->action);
	Assert::same('App\Ai\OpenAiFailure: OPENAI_CLASSIFIER_MODEL is not set.', $decision?->error);

	$detail = $browser->get("/admin/events/detail/$eventId");
	Assert::same(200, $detail->status);
	Assert::contains('Obsah komentářů a zpráv vidí jen moderátor a administrátor.', $detail->body);
	Assert::notContains('Synthetic comment', $detail->body, 'A viewer never sees customer content.');
	Assert::contains('OPENAI_CLASSIFIER_MODEL is not set.', $detail->body);
	Assert::contains('klasifikace selhala', $detail->body);

	$moderator = E2eBrowser::create();
	[$email, $password] = $data->operator('moderator');
	Assert::same(303, $moderator->signIn($email, $password)->status);
	$detail = $moderator->get("/admin/events/detail/$eventId");
	Assert::contains('Synthetic comment', $detail->body);
	Assert::contains('Synthetic Customer', $detail->body);
	Assert::same(1, $data->database->query(
		"SELECT COUNT(*) FROM admin_audit WHERE action = 'event.content.view' AND target_id = ?",
		(string) $eventId,
	)->fetchField());

	// Observe mode: the comment shows its state, but no action can be requested.
	Assert::contains('komentář viditelný', $detail->body);
	Assert::contains('Pravidla jsou v režimu pozorování', $detail->body);
	Assert::null($detail->link('~do=request~'));
	Assert::same(403, $browser->get("/admin/events/detail/$eventId?do=request&type=hide")->status, 'A viewer cannot request an action.');
	Assert::same(302, $moderator->get("/admin/events/detail/$eventId?do=request&type=hide")->status);
	Assert::same(0, $data->database->query('SELECT COUNT(*) FROM moderation_actions')->fetchField());

	// A reply draft is shown to the moderator only; observe mode offers no approval.
	$data->database->query('INSERT INTO moderation_actions ?', [
		'comment_id' => $data->database->query('SELECT id FROM social_comments WHERE external_id = ?', "fb:$commentId")->fetchField(),
		'event_id' => $eventId,
		'type' => 'reply',
		'status' => 'pending',
		'automatic' => false,
		'external_target_id' => "fb:$commentId",
		'text_encrypted' => (new App\Core\Encryptor((string) getenv('APP_ENCRYPTION_KEY')))->encrypt('Synthetic draft reply', App\Outbox\Actions::TextContext),
	]);
	$detail = $moderator->get("/admin/events/detail/$eventId");
	Assert::contains('Synthetic draft reply', $detail->body);
	Assert::contains('Odpovědět na komentář', $detail->body);
	Assert::notContains('Schválit odpověď', $detail->body);
	Assert::notContains('Synthetic draft reply', $browser->get("/admin/events/detail/$eventId")->body, 'A viewer never sees the reply text.');

	// A failed decision can be requested again.
	$link = $detail->link('~do=decide~') ?? throw new LogicException('Missing decide link.');
	Assert::same(302, $moderator->get(html_entity_decode($link))->status);
	Assert::same(2, $data->database->query(
		"SELECT COUNT(*) FROM queue_jobs WHERE type = 'event.decide' AND subject_id = ?",
		$eventId,
	)->fetchField());
	Assert::same(403, $browser->get("/admin/events/detail/$eventId?do=decide")->status, 'A viewer cannot request a decision.');
});
