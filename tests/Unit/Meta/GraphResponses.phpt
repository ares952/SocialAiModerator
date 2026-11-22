<?php

declare(strict_types=1);

use App\Meta\MetaFailure;
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
