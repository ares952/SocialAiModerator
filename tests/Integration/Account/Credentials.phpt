<?php

declare(strict_types=1);

use App\Account\Credentials;
use App\Account\InvalidCredential;
use App\Account\TokenProvider;
use App\Account\TokenStatus;
use App\Account\TokenType;
use App\Account\TokenUnavailable;
use App\Audit\AuditLog;
use App\Console\AccountTokenCommand;
use App\Core\Encryptor;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tester\Assert;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

// Encrypted Meta access tokens (specification §6, §8.2). Synthetic tokens only.

$database = TestDatabase::create();
$encryptor = new Encryptor(base64_encode(random_bytes(32)));
$credentials = new Credentials($database, $encryptor, new AuditLog($database));
$provider = new TokenProvider($database, $encryptor);
$database->query('INSERT INTO social_accounts ?', [
	[
		'platform' => 'instagram',
		'external_id' => 'ig:1700000000000001',
		'name' => 'Test Instagram',
		'status' => 'active',
	],
	[
		'platform' => 'facebook',
		'external_id' => 'fb:1000000000000001',
		'name' => 'Test Page',
		'status' => 'active',
	],
]);
$scopes = [
	'instagram_basic',
	'instagram_manage_comments',
	'instagram_manage_messages',
];


test('a token is stored encrypted with its metadata and audited without the token', function () use ($credentials, $provider, $database, $encryptor, $scopes): void {
	$credentials->replace(1, 'SYNTHETIC-TOKEN-ONE', TokenType::SystemUser, $scopes, null, new DateTimeImmutable('2099-01-01 00:00:00 UTC'), operatorId: null);

	$stored = $database->query('SELECT encrypted_token FROM account_credentials')->fetchField();
	Assert::type('string', $stored);
	Assert::notContains('SYNTHETIC', $stored);

	$row = $credentials->find(1) ?? throw new LogicException;
	Assert::same('system_user', $row->token_type);
	Assert::same('instagram_basic,instagram_manage_comments,instagram_manage_messages', $row->scopes);
	Assert::null($row->expires_at);
	Assert::same('2099-01-01 00:00:00', $row->data_access_expires_at->format('Y-m-d H:i:s'));
	Assert::same('unchecked', $row->status);
	Assert::null($row->checked_at);
	Assert::same($encryptor->currentKeyId(), $row->key_id);
	Assert::false(isset($row->encrypted_token));

	$token = $provider->token(1);
	Assert::same('SYNTHETIC-TOKEN-ONE', $token->value);
	Assert::same(TokenType::SystemUser, $token->type);

	$audit = $database->query('SELECT action, target_id, details FROM admin_audit')->fetch() ?? throw new LogicException;
	Assert::same('account.token.replace', $audit->action);
	Assert::same('1', (string) $audit->target_id);
	Assert::notContains('SYNTHETIC', (string) $audit->details);
	Assert::contains('expires=never', (string) $audit->details);
});


test('replacing a token applies at once and resets the check', function () use ($credentials, $provider, $database, $scopes): void {
	$credentials->recordCheck(1, TokenStatus::Valid);
	Assert::same('valid', $credentials->find(1)?->status);

	$credentials->replace(1, 'SYNTHETIC-TOKEN-TWO', TokenType::SystemUser, $scopes, new DateTimeImmutable('2099-06-01 12:00:00 UTC'), null, operatorId: null);

	Assert::same('SYNTHETIC-TOKEN-TWO', $provider->token(1)->value);
	Assert::same(1, $database->table('account_credentials')->count('*'));
	$row = $credentials->find(1) ?? throw new LogicException;
	Assert::same('unchecked', $row->status);
	Assert::null($row->checked_at);
	Assert::null($row->data_access_expires_at);
	Assert::same('2099-06-01 12:00:00', $row->expires_at->format('Y-m-d H:i:s'));
	Assert::same(2, $database->table('admin_audit')->count('*'));
});


test('a missing, invalid or expired token is unavailable', function () use ($credentials, $provider, $database): void {
	Assert::exception(fn() => $provider->token(2), TokenUnavailable::class, 'Account 2 has no access token.');

	$credentials->replace(2, 'SYNTHETIC-PAGE-TOKEN', TokenType::Page, ['pages_messaging'], null, null, operatorId: null);
	Assert::same(TokenType::Page, $provider->token(2)->type);

	$credentials->recordCheck(2, TokenStatus::Invalid);
	Assert::exception(fn() => $provider->token(2), TokenUnavailable::class, '%a%is invalid%a%');

	$database->query("UPDATE account_credentials SET status = 'valid', expires_at = NOW() - INTERVAL 1 MINUTE WHERE social_account_id = 2");
	Assert::exception(fn() => $provider->token(2), TokenUnavailable::class, '%a%has expired%a%');

	$database->query('UPDATE account_credentials SET expires_at = NULL, data_access_expires_at = NOW() - INTERVAL 1 MINUTE WHERE social_account_id = 2');
	Assert::exception(fn() => $provider->token(2), TokenUnavailable::class, '%a%no data access%a%');
});


test('a token encrypted with a key that is no longer configured does not decrypt', function () use ($database): void {
	$provider = new TokenProvider($database, new Encryptor(base64_encode(random_bytes(32))));
	Assert::exception(fn() => $provider->token(1), App\Core\DecryptionFailed::class);
});


test('invalid tokens, scopes or accounts are rejected', function () use ($credentials, $scopes): void {
	foreach ([
		[1, '', $scopes],
		[1, 'with space', $scopes],
		[1, str_repeat('a', 2001), $scopes],
		[1, 'SYNTHETIC', []],
		[1, 'SYNTHETIC', ['Instagram Basic']],
		[99, 'SYNTHETIC', $scopes],
	] as [$account, $token, $tokenScopes]) {
		Assert::exception(
			fn() => $credentials->replace($account, $token, TokenType::Page, $tokenScopes, null, null, operatorId: null),
			InvalidCredential::class,
		);
	}
});


test('the console asks for the token without echo and stores it', function () use ($credentials, $provider): void {
	$application = new Application;
	$application->addCommands([
		new AccountTokenCommand($credentials),
	]);
	$command = new CommandTester($application->find('account:token'));

	$command->setInputs(['SYNTHETIC-CONSOLE-TOKEN']);
	Assert::same(0, $command->execute([
		'account' => '2',
		'--type' => 'page',
		'--scopes' => 'pages_messaging, pages_read_engagement',
		'--expires' => 'never',
		'--data-access-expires' => '2099-02-01',
	]));
	Assert::contains('token of account 2 stored (page, key ', $command->getDisplay());
	Assert::notContains('SYNTHETIC', $command->getDisplay());
	Assert::same('SYNTHETIC-CONSOLE-TOKEN', $provider->token(2)->value);
	Assert::same('pages_messaging,pages_read_engagement', $credentials->find(2)?->scopes);

	foreach ([
		['--type' => 'user', '--scopes' => 'pages_messaging', '--expires' => 'never'],
		['--type' => 'page', '--scopes' => 'pages_messaging'],
		['--type' => 'page', '--scopes' => 'pages_messaging', '--expires' => '2099-02-30'],
		['--type' => 'page', '--scopes' => 'pages_messaging', '--expires' => 'tomorrow'],
	] as $options) {
		$command->setInputs(['SYNTHETIC-REJECTED']);
		Assert::same(1, $command->execute(['account' => '2'] + $options), Nette\Utils\Json::encode($options));
	}
	Assert::same('SYNTHETIC-CONSOLE-TOKEN', $provider->token(2)->value);
});
