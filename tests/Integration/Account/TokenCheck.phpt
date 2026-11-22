<?php

declare(strict_types=1);

use App\Account\Accounts;
use App\Account\Credentials;
use App\Account\TokenStatus;
use App\Account\TokenType;
use App\Audit\AuditLog;
use App\Core\Encryptor;
use App\Meta\MetaFailure;
use App\Meta\Platform;
use App\Meta\TokenInfo;
use Nette\Utils\FileSystem;
use Tester\Assert;
use Tests\Fakes\FakeGraphReader;
use Tests\Support\TestDatabase;
use Tests\Support\TestLog;
use Tests\Support\TestServices;

require __DIR__ . '/../../bootstrap.php';

// Token checks against Meta with the fake Graph reader (synthetic tokens; nothing leaves the test).

$database = TestDatabase::create();
$encryptor = new Encryptor(base64_encode(random_bytes(32)));
$credentials = new Credentials($database, $encryptor, new AuditLog($database));
$graph = new FakeGraphReader;
$log = new TestLog('token-check.log');
$check = TestServices::tokenCheck($database, $encryptor, $graph, $log->logger, appId: '1234567890');
$database->query('INSERT INTO social_accounts ?', [
	'platform' => 'facebook',
	'external_id' => 'fb:1000000000000001',
	'name' => 'Test Page',
	'status' => 'active',
]);
$accountId = (int) $database->getInsertId();
$status = fn(): array => (array) $database->query(
	'SELECT status, scopes, expires_at FROM account_credentials WHERE social_account_id = ?',
	$accountId,
)->fetch();
$info = static fn(array $scopes, bool $valid = true, string $app = '1234567890'): TokenInfo => new TokenInfo(
	valid: $valid,
	appId: $app,
	type: 'PAGE',
	expiresAt: null,
	dataAccessExpiresAt: new DateTimeImmutable('2027-02-01 00:00:00'),
	scopes: array_values(array_map(strval(...), $scopes)),
	targets: [],
);
$store = static fn(string $token) => $credentials->replace($accountId, $token, TokenType::Page, ['pages_messaging'], new DateTimeImmutable('+1 day'), null, null);


test('a valid token with every permission: Meta\'s expiry and scopes replace the typed ones', function () use ($check, $graph, $store, $status, $info, $accountId): void {
	$store('synthetic-token-1');
	$graph->answer('synthetic-token-1', $info(Platform::Facebook->requiredScopes()));
	$result = $check->account($accountId);
	Assert::true($result->ok());
	Assert::same(TokenStatus::Valid, $result->status);
	$row = $status();
	Assert::same('valid', $row['status']);
	Assert::null($row['expires_at'], 'Meta says the token never expires.');
	Assert::same(implode(',', Platform::Facebook->requiredScopes()), $row['scopes']);
});


test('missing permissions keep the token valid and are reported', function () use ($check, $graph, $store, $info, $accountId, $database): void {
	$store('synthetic-token-2');
	$graph->answer('synthetic-token-2', $info(['pages_messaging', 'pages_read_engagement']));
	$result = $check->account($accountId);
	Assert::same(TokenStatus::Valid, $result->status);
	Assert::false($result->ok());
	Assert::same(['pages_manage_engagement', 'pages_manage_metadata', 'pages_read_user_content'], $result->missingScopes);
	$accounts = (new Accounts($database, new AuditLog($database)))->all();
	Assert::same(['pages_manage_engagement', 'pages_manage_metadata', 'pages_read_user_content'], $accounts[0]->missing_scopes);
});


test('an invalid token or one of another app is marked invalid', function () use ($check, $graph, $store, $status, $info, $accountId): void {
	$store('synthetic-token-3');
	$graph->answer('synthetic-token-3', $info([], valid: false));
	Assert::same(TokenStatus::Invalid, $check->account($accountId)->status);
	Assert::same('invalid', $status()['status']);

	$store('synthetic-token-4');
	$graph->answer('synthetic-token-4', $info(Platform::Facebook->requiredScopes(), app: '999'));
	$result = $check->account($accountId);
	Assert::same(TokenStatus::Invalid, $result->status);
	Assert::same('The token belongs to another Meta app.', $result->problem);
});


test('a failed check leaves the stored state; an invalid token is checked again', function () use ($check, $graph, $store, $status, $info, $accountId, $log): void {
	$store('synthetic-token-5');
	$graph->answer('synthetic-token-5', new MetaFailure('Graph GET /debug_token failed with HTTP 0.', uncertain: false, transient: true));
	$result = $check->account($accountId);
	Assert::null($result->status);
	Assert::same('unchecked', $status()['status']);

	$graph->answer('synthetic-token-5', $info([], valid: false));
	$check->account($accountId);
	$graph->answer('synthetic-token-5', $info(Platform::Facebook->requiredScopes()));
	Assert::same(TokenStatus::Valid, $check->account($accountId)->status, 'Fixed on Meta\'s side, valid again.');
	Assert::same(1, count($check->all()));

	Assert::contains('warning: Token check failed', $log->messages());
	Assert::notContains('synthetic-token', FileSystem::read(TEMP_DIR . '/token-check.log'), 'Tokens are never logged.');
});
