<?php

declare(strict_types=1);

use App\Ingest\WebhookBatch;
use App\Meta\EventNormalizer;
use App\Meta\EventType;
use App\Meta\NormalizedEvent;
use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

// Synthetic payloads only (tests/fixtures/meta, no real IDs or customer content).

$normalizer = new EventNormalizer;

$single = static function (string $body) use ($normalizer): NormalizedEvent {
	$events = WebhookBatch::parse($body)->events;
	Assert::count(1, $events);

	return $normalizer->normalize($events[0]->payload);
};

$fixture = static fn (string $name): NormalizedEvent => $single(FileSystem::read(__DIR__ . "/../../fixtures/meta/$name.json"));

$time = static fn (NormalizedEvent $event): ?string => $event->occurredAt?->format('Y-m-d H:i:s.v e');


test('every fixture maps to its type, external ID and time', function () use ($fixture, $time): void {
	$expected = [
		'page-comment' => [EventType::CommentCreated, 'fb:2200000000000001_2300000000000001', '2025-11-20 12:00:00.000'],
		'page-comment-reply' => [EventType::CommentCreated, 'fb:2200000000000001_2300000000000002', '2025-11-20 12:01:00.000'],
		'page-comment-edited' => [EventType::CommentEdited, 'fb:2200000000000001_2300000000000001', '2025-11-20 12:02:00.000'],
		'page-comment-removed' => [EventType::CommentRemoved, 'fb:2200000000000001_2300000000000001', '2025-11-20 12:03:00.000'],
		'page-comment-hidden' => [EventType::CommentHidden, 'fb:2200000000000001_2300000000000001', '2025-11-20 12:04:00.000'],
		'page-post' => [EventType::Other, null, '2025-11-20 11:43:20.000'],
		'page-reaction' => [EventType::Other, null, '2025-11-20 12:05:00.000'],
		'page-message' => [EventType::MessageReceived, 'fb:m_synthetic_page_message_1', '2025-11-20 12:00:00.500'],
		'page-message-echo' => [EventType::MessageEcho, 'fb:m_synthetic_page_message_2', '2025-11-20 12:01:00.500'],
		'page-postback' => [EventType::Postback, 'fb:m_synthetic_postback_1', '2025-11-20 12:06:40.000'],
		'page-message-read' => [EventType::Other, null, '2025-11-20 12:08:20.000'],
		'page-message-dashboard-test' => [EventType::MessageReceived, 'fb:test_message_id', '2018-05-27 22:23:44.000'],
		'instagram-comment' => [EventType::CommentCreated, 'ig:1800000000000001', '2025-11-20 12:00:00.000'],
		'instagram-comment-reply' => [EventType::CommentCreated, 'ig:1800000000000002', '2025-11-20 12:10:00.000'],
		'instagram-message' => [EventType::MessageReceived, 'ig:aWdfZAG1faXRlbToxOklHTWVzc2FnZAUlEOjE3MDAwMDAwMDAwMDAwMDE6c3ludGhldGljMQ', '2025-11-20 12:00:00.500'],
		'instagram-message-echo' => [EventType::MessageEcho, 'ig:aWdfZAG1faXRlbToxOklHTWVzc2FnZAUlEOjE3MDAwMDAwMDAwMDAwMDE6c3ludGhldGljMg', '2025-11-20 12:01:00.500'],
		'instagram-message-deleted' => [EventType::MessageDeleted, 'ig:aWdfZAG1faXRlbToxOklHTWVzc2FnZAUlEOjE3MDAwMDAwMDAwMDAwMDE6c3ludGhldGljMQ', '2025-11-20 12:02:00.500'],
	];
	Assert::same(
		count($expected),
		count(glob(__DIR__ . '/../../fixtures/meta/*.json') ?: []),
		'Every fixture has an expectation.',
	);

	foreach ($expected as $name => [$type, $externalId, $occurredAt]) {
		$event = $fixture($name);
		Assert::same($type, $event->type, $name);
		Assert::same($externalId, $event->externalId, $name);
		Assert::same("$occurredAt +00:00", $time($event), $name);
		Assert::null($event->problem, $name);
	}
});


test('columns are ready for the social_events row', function () use ($fixture): void {
	Assert::same([
		'event_type' => 'comment.edited',
		'external_id' => 'fb:2200000000000001_2300000000000001',
		'occurred_at' => '2025-11-20 12:02:00.000',
		'last_error' => null,
	], $fixture('page-comment-edited')->columns());
});


$feed = static fn (array $value): string => Json::encode([
	'object' => 'page',
	'entry' => [
		[
			'id' => '1000000000000001',
			'time' => 1763640000,
			'changes' => [
				[
					'field' => 'feed',
					'value' => $value,
				],
			],
		],
	],
]);

$messaging = static fn (array $item, string $object = 'instagram', string $list = 'messaging'): string => Json::encode([
	'object' => $object,
	'entry' => [
		[
			'id' => '1700000000000001',
			'time' => 1763640000000,
			$list => [
				$item + [
					'sender' => ['id' => '5100000000000001'],
					'recipient' => ['id' => '1700000000000001'],
					'timestamp' => 1763640000000,
				],
			],
		],
	],
]);


test('unknown structures are unsupported with a reason that never repeats customer text', function () use ($single, $feed, $messaging): void {
	$cases = [
		'feed comment verb `?` is not supported' => $feed([
			'item' => 'comment',
			'verb' => 'Synthetic customer text',
			'comment_id' => '1_2',
		]),
		'feed comment verb `block` is not supported' => $feed([
			'item' => 'comment',
			'verb' => 'block',
			'comment_id' => '1_2',
		]),
		'feed comment has no comment_id' => $feed([
			'item' => 'comment',
			'verb' => 'add',
		]),
		'feed item `status` is not supported' => $feed([
			'item' => 'status',
			'verb' => 'add',
			'from' => ['id' => '2100000000000001'],
			'message' => 'Synthetic visitor post',
		]),
		'change `mentions` is not supported' => Json::encode([
			'object' => 'instagram',
			'entry' => [
				[
					'id' => '1700000000000001',
					'changes' => [
						['field' => 'mentions', 'value' => ['media_id' => '1']],
					],
				],
			],
		]),
		'change `comments` has no value' => Json::encode([
			'object' => 'instagram',
			'entry' => [
				[
					'id' => '1700000000000001',
					'changes' => [
						['field' => 'comments'],
					],
				],
			],
		]),
		'comment has no id' => Json::encode([
			'object' => 'instagram',
			'entry' => [
				[
					'id' => '1700000000000001',
					'changes' => [
						['field' => 'comments', 'value' => ['text' => 'Synthetic comment']],
					],
				],
			],
		]),
		'message has no mid' => $messaging([
			'message' => ['text' => 'Synthetic message'],
		]),
		'messaging item `optin,?` is not supported' => $messaging([
			'optin' => ['ref' => 'x'],
			'Synthetic Key' => 1,
		]),
		'standby (handover protocol) is not supported' => $messaging([
			'message' => ['mid' => 'm_1', 'text' => 'Synthetic message'],
		], 'page', 'standby'),
	];

	foreach ($cases as $problem => $body) {
		$event = $single($body);
		Assert::same(EventType::Unsupported, $event->type, $problem);
		Assert::same($problem, $event->problem);
		Assert::null($event->externalId);
		Assert::same($problem, $event->columns()['last_error']);
	}
});


test('a deleted echo counts as deleted; numeric IDs become strings', function () use ($single, $messaging, $feed): void {
	$event = $single($messaging([
		'message' => ['mid' => 'm_1', 'is_echo' => true, 'is_deleted' => true],
	]));
	Assert::same(EventType::MessageDeleted, $event->type);

	$event = $single($feed([
		'item' => 'comment',
		'verb' => 'add',
		'comment_id' => 2300000000000001,
		'created_time' => 1763640000,
	]));
	Assert::same('fb:2300000000000001', $event->externalId);
});


test('a missing or malformed time is null, never guessed', function () use ($single, $messaging, $feed): void {
	foreach (['', '0', '-5', 'yesterday', 0, -1, 1.5, null] as $timestamp) {
		$event = $single($messaging([
			'message' => ['mid' => 'm_1'],
			'timestamp' => $timestamp,
		]));
		Assert::same(EventType::MessageReceived, $event->type);
		Assert::null($event->occurredAt, var_export($timestamp, true));
	}

	$event = $single($feed([
		'item' => 'comment',
		'verb' => 'add',
		'comment_id' => '1_2',
	]));
	Assert::null($event->occurredAt);
});


test('a postback without mid has no external ID', function () use ($single, $messaging): void {
	$event = $single($messaging([
		'postback' => ['title' => 'Synthetic', 'payload' => 'X'],
	], 'page'));
	Assert::same(EventType::Postback, $event->type);
	Assert::null($event->externalId);
});


test('customer text, author and post link are read but never part of the columns', function () use ($fixture, $single, $feed): void {
	$event = $fixture('page-comment');
	Assert::same('Synthetic comment: is there a discount?', $event->text);
	Assert::same('Synthetic Customer', $event->author);
	Assert::same('https://www.facebook.com/example/posts/1', $event->permalink);
	Assert::same(['event_type', 'external_id', 'occurred_at', 'last_error'], array_keys($event->columns()));

	$event = $fixture('instagram-comment');
	Assert::same(['Synthetic comment: how much is the course?', 'synthetic.customer', null], [$event->text, $event->author, $event->permalink]);

	$event = $fixture('instagram-message');
	Assert::same([EventType::MessageReceived, null], [$event->type, $event->author]);
	Assert::notSame(null, $event->text);
	Assert::null($fixture('instagram-message-deleted')->text);
	Assert::null($fixture('page-reaction')->text);

	$event = $single($feed([
		'item' => 'comment',
		'verb' => 'add',
		'comment_id' => '1_2',
		'message' => '   ',
		'post' => ['permalink_url' => 'https://evil.example/phish'],
	]));
	Assert::same([null, null], [$event->text, $event->permalink], 'Blank text and foreign links are dropped.');
});


test('comments carry their author, post, parent and whether the account wrote them', function () use ($fixture, $single, $feed): void {
	$shape = static fn (NormalizedEvent $event): array => [$event->authorId, $event->postId, $event->parentId, $event->own];

	Assert::same(['fb:2100000000000001', 'fb:1000000000000001_2200000000000001', null, false], $shape($fixture('page-comment')), 'A top-level comment has no parent.');
	Assert::same(
		['fb:2100000000000001', 'fb:1000000000000001_2200000000000001', 'fb:2200000000000001_2300000000000001', false],
		$shape($fixture('page-comment-reply')),
	);
	Assert::same(['ig:1780000000000001', 'ig:1790000000000001', null, false], $shape($fixture('instagram-comment')));
	Assert::same(['ig:1780000000000002', 'ig:1790000000000001', 'ig:1800000000000001', false], $shape($fixture('instagram-comment-reply')));

	$own = $single($feed([
		'item' => 'comment',
		'verb' => 'add',
		'comment_id' => '1_2',
		'message' => 'Synthetic reply of the Page',
		'from' => ['id' => '1000000000000001', 'name' => 'Test Page'],
	]));
	Assert::true($own->own);

	$ownInstagram = WebhookBatch::parse(Json::encode([
		'object' => 'instagram',
		'entry' => [
			[
				'id' => '1700000000000001',
				'time' => 1763640000,
				'changes' => [
					['field' => 'comments', 'value' => ['id' => '18', 'text' => 'Synthetic reply', 'from' => ['id' => '1700000000000001', 'username' => 'account']]],
				],
			],
		],
	]))->events[0];
	Assert::true((new EventNormalizer)->normalize($ownInstagram->payload)->own);
});


test('a message belongs to its customer: the sender, or the recipient of an echo', function () use ($fixture): void {
	Assert::same('ig:5100000000000001', $fixture('instagram-message')->customerId);
	Assert::same('ig:5100000000000001', $fixture('instagram-message-echo')->customerId);
	Assert::same('ig:5100000000000001', $fixture('instagram-message-deleted')->customerId);
	Assert::same('fb:6100000000000001', $fixture('page-message-echo')->customerId);
	Assert::same('fb:12334', $fixture('page-message-dashboard-test')->customerId);
	Assert::null($fixture('page-comment')->customerId);
});


test('an echo carries the sending app and its metadata, other messages do not', function () use ($fixture, $single, $messaging): void {
	Assert::same(['1000000000000099', null], [$fixture('page-message-echo')->appId, $fixture('page-message-echo')->metadata]);
	Assert::same([null, null], [$fixture('instagram-message-echo')->appId, $fixture('instagram-message-echo')->metadata]);
	Assert::null($fixture('page-message')->appId);

	$echo = $single($messaging([
		'message' => ['mid' => 'm_2', 'is_echo' => true, 'app_id' => 42, 'metadata' => 'reply:17'],
	]));
	Assert::same(['42', 'reply:17'], [$echo->appId, $echo->metadata]);
});
