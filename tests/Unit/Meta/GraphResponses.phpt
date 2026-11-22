<?php

declare(strict_types=1);

use App\Meta\CommentInfo;
use App\Meta\HttpMetaClient;
use App\Meta\MetaFailure;
use App\Meta\Platform;
use App\Meta\TokenInfo;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

// Graph API answers (shapes from developers.facebook.com, synthetic values).


test('debug_token: validity, app, expiry (0 = never), scopes and granular targets', function (): void {
	$info = TokenInfo::fromApi([
		'data' => [
			'app_id' => 1234567890,
			'type' => 'PAGE',
			'is_valid' => true,
			'expires_at' => 0,
			'data_access_expires_at' => 1795000000,
			'scopes' => [
				'pages_messaging',
				'pages_read_engagement',
			],
			'granular_scopes' => [
				[
					'scope' => 'pages_messaging',
					'target_ids' => [
						1000000000000001,
					],
				],
			],
		],
	]);
	Assert::true($info->valid);
	Assert::same('1234567890', $info->appId);
	Assert::same('PAGE', $info->type);
	Assert::null($info->expiresAt);
	Assert::same('2026-11-18 11:06:40', $info->dataAccessExpiresAt?->format('Y-m-d H:i:s'));
	Assert::same(['pages_messaging', 'pages_read_engagement'], $info->scopes);
	Assert::same(['pages_messaging' => ['1000000000000001']], $info->targets);

	$invalid = TokenInfo::fromApi([
		'data' => [
			'is_valid' => false,
			'error' => [
				'code' => 190,
				'message' => 'Session has expired',
			],
		],
	]);
	Assert::false($invalid->valid);
	Assert::same(190, $invalid->errorCode);
	Assert::exception(fn() => TokenInfo::fromApi(['error' => []]), MetaFailure::class);
});


test('failures: rate limits are transient, a write without an answer is uncertain', function (): void {
	$limit = MetaFailure::fromGraph('GET /{id}', 400, ['error' => ['code' => 80001, 'message' => 'x']], write: false);
	Assert::true($limit->transient);
	Assert::false($limit->uncertain);
	Assert::same('Graph GET /{id} failed with HTTP 400 (code 80001).', $limit->getMessage());

	$expired = MetaFailure::fromGraph('POST /{id}', 400, ['error' => ['code' => 190, 'error_subcode' => 463]], write: true);
	Assert::false($expired->transient);
	Assert::false($expired->uncertain);
	Assert::true($expired->invalidToken());
	Assert::same(463, $expired->errorSubcode);

	$timeout = MetaFailure::fromGraph('POST /{id}/messages', 0, null, write: true);
	Assert::true($timeout->uncertain, 'The message may have been sent.');
	Assert::false($timeout->transient, 'Never retried blindly.');

	$readTimeout = MetaFailure::fromGraph('GET /debug_token', 502, null, write: false);
	Assert::true($readTimeout->transient);
	Assert::false($readTimeout->uncertain);
	Assert::true(MetaFailure::fromGraph('GET /x', 429, null, write: false)->transient);
	Assert::false(MetaFailure::fromGraph('GET /x', 400, ['error' => ['code' => 10]], write: false)->transient);
});


test('comments: field names differ per platform, IDs get the platform prefix', function (): void {
	$facebook = CommentInfo::fromApi(Platform::Facebook, [
		'id' => '122103099423552061_1000000000000001',
		'message' => 'Synthetic',
		'from' => [
			'id' => '2900000000000001',
		],
		'created_time' => '2026-11-21T17:20:07+0000',
		'is_hidden' => false,
		'can_hide' => true,
	]);
	Assert::same('fb:122103099423552061_1000000000000001', $facebook->id);
	Assert::same('fb:2900000000000001', $facebook->authorId);
	Assert::same('2026-11-21 17:20:07', $facebook->createdAt?->format('Y-m-d H:i:s'));
	Assert::false($facebook->hidden);
	Assert::true($facebook->canHide);
	Assert::null($facebook->parentId);

	$instagram = CommentInfo::fromApi(Platform::Instagram, [
		'id' => '18000000000000001',
		'text' => 'Synthetic',
		'from' => [
			'id' => '29000000000000001',
			'username' => 'synthetic',
		],
		'timestamp' => '2026-11-21T19:25:26+0000',
		'hidden' => true,
		'parent_id' => '18000000000000000',
	]);
	Assert::same('ig:18000000000000001', $instagram->id);
	Assert::same('Synthetic', $instagram->text);
	Assert::true($instagram->hidden);
	Assert::null($instagram->canHide, 'Instagram has no can_hide.');
	Assert::same('ig:18000000000000000', $instagram->parentId);
	Assert::exception(fn() => CommentInfo::fromApi(Platform::Instagram, []), MetaFailure::class);
});


test('writes: paths and fields per platform; IDs must be IDs of that platform', function (): void {
	Assert::same(['/122_456', ['is_hidden' => 'true']], HttpMetaClient::request('hide', Platform::Facebook, 'fb:122_456', hidden: true));
	Assert::same(['/18000000000000001', ['hide' => 'false']], HttpMetaClient::request('hide', Platform::Instagram, 'ig:18000000000000001', hidden: false));
	Assert::same(['/122_456/comments', ['message' => 'Synthetic reply']], HttpMetaClient::request('reply', Platform::Facebook, 'fb:122_456', text: 'Synthetic reply'));
	Assert::same(['/18000000000000001/replies', ['message' => 'Synthetic reply']], HttpMetaClient::request('reply', Platform::Instagram, 'ig:18000000000000001', text: 'Synthetic reply'));
	Assert::same([
		'/1000000000000001/messages',
		[
			'recipient' => '{"id":"2100000000000001"}',
			'messaging_type' => 'RESPONSE',
			'message' => '{"text":"Synthetic","metadata":"outbox-key"}',
		],
	], HttpMetaClient::request('message', Platform::Facebook, 'fb:1000000000000001', recipient: 'fb:2100000000000001', text: 'Synthetic', metadata: 'outbox-key'));
	Assert::exception(fn() => HttpMetaClient::request('hide', Platform::Facebook, 'ig:18000000000000001', hidden: true), InvalidArgumentException::class);
	Assert::exception(fn() => HttpMetaClient::request('hide', Platform::Facebook, 'fb:../me', hidden: true), InvalidArgumentException::class);
});
