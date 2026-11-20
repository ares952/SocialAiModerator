<?php

declare(strict_types=1);

use App\Ingest\InvalidWebhookPayload;
use App\Ingest\WebhookBatch;
use App\Meta\Platform;
use Nette\Utils\Json;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

// Synthetic payloads only (no real IDs or customer content).


test('every change of every entry is one event, unknown fields are ignored', function (): void {
	$batch = WebhookBatch::parse(Json::encode([
		'object' => 'page',
		'unknown_top_level' => true,
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
							'comment_id' => '1000000000000001_2000000000000001',
							'message' => 'Synthetic comment',
							'new_unknown_field' => [1, 2],
						],
					],
					[
						'field' => 'feed',
						'value' => [
							'item' => 'comment',
							'verb' => 'remove',
							'comment_id' => '1000000000000001_2000000000000001',
						],
					],
				],
			],
			[
				'id' => '1000000000000002',
				'time' => 1763640001,
				'unknown_list' => [
					['x' => 1],
				],
				'messaging' => [
					[
						'sender' => ['id' => '3000000000000001'],
						'recipient' => ['id' => '1000000000000002'],
						'timestamp' => 1763640001000,
						'message' => ['mid' => 'm_synthetic_1', 'text' => 'Synthetic message'],
					],
				],
			],
		],
	]));

	Assert::count(3, $batch->events);
	Assert::same(Platform::Facebook, $batch->events[0]->platform);
	Assert::same('fb:1000000000000001', $batch->events[0]->accountExternalId);
	Assert::same('feed', $batch->events[0]->topic);
	Assert::same('fb:1000000000000002', $batch->events[2]->accountExternalId);
	Assert::same('messaging', $batch->events[2]->topic);
	Assert::notSame($batch->events[0]->eventKey, $batch->events[1]->eventKey);
	Assert::match('%h%', $batch->events[0]->eventKey);
	Assert::same(64, strlen($batch->events[0]->eventKey));

	$payload = Json::decode($batch->events[0]->payload, forceArrays: true);
	Assert::same('1000000000000001', $payload['entry_id']);
	Assert::same('Synthetic comment', $payload['item']['value']['message']);
});


test('instagram object maps to the instagram platform', function (): void {
	$batch = WebhookBatch::parse(Json::encode([
		'object' => 'instagram',
		'entry' => [
			[
				'id' => '1700000000000001',
				'time' => 1763640000,
				'changes' => [
					[
						'field' => 'comments',
						'value' => ['id' => '1800000000000001', 'text' => 'Synthetic'],
					],
				],
			],
		],
	]));

	Assert::same(Platform::Instagram, $batch->events[0]->platform);
	Assert::same('ig:1700000000000001', $batch->events[0]->accountExternalId);
	Assert::same('comments', $batch->events[0]->topic);
});


test('numeric IDs beyond 64 bits stay exact strings', function (): void {
	$batch = WebhookBatch::parse('{"object":"page","entry":[{"id":123456789012345678901234,"changes":[{"field":"feed","value":{}}]}]}');
	Assert::same('fb:123456789012345678901234', $batch->events[0]->accountExternalId);
});


test('event key ignores key order and delivery time, but not content', function (): void {
	$key = static fn(string $body): string => WebhookBatch::parse($body)->events[0]->eventKey;

	$base = $key('{"object":"page","entry":[{"id":"1","time":1,"changes":[{"field":"feed","value":{"a":1,"b":2}}]}]}');
	Assert::same($base, $key('{"object":"page","entry":[{"time":2,"id":"1","changes":[{"value":{"b":2,"a":1},"field":"feed"}]}]}'));
	Assert::notSame($base, $key('{"object":"page","entry":[{"id":"1","time":1,"changes":[{"field":"feed","value":{"a":1,"b":3}}]}]}'));
});


test('messages are keyed by mid', function (): void {
	$key = static fn(string $message): string => WebhookBatch::parse(
		'{"object":"page","entry":[{"id":"1","messaging":[{"timestamp":1,"message":' . $message . '}]}]}',
	)->events[0]->eventKey;

	Assert::same($key('{"mid":"m_1","text":"a"}'), $key('{"mid":"m_1","text":"a","is_echo":false}'));
	Assert::notSame($key('{"mid":"m_1","text":"a"}'), $key('{"mid":"m_2","text":"a"}'));
});


test('entry without items gives no event', function (): void {
	Assert::same([], WebhookBatch::parse('{"object":"page","entry":[{"id":"1","time":1}]}')->events);
});


test('malformed or unsupported bodies are rejected', function (): void {
	foreach ([
		'',
		'not json',
		'[]',
		'{"object":"page"}',
		'{"object":"page","entry":{"id":"1"}}',
		'{"object":"user","entry":[]}',
		'{"object":"page","entry":["x"]}',
		'{"object":"page","entry":[{"changes":[]}]}',
		'{"object":"page","entry":[{"id":"1","changes":{"field":"feed"}}]}',
		'{"object":"page","entry":[{"id":"1","changes":[{"value":{}}]}]}',
		'{"object":"page","entry":[{"id":"1","messaging":["x"]}]}',
	] as $body) {
		Assert::exception(fn() => WebhookBatch::parse($body), InvalidWebhookPayload::class);
	}
});
