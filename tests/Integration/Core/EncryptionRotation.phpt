<?php

declare(strict_types=1);

use App\Account\Credentials;
use App\Account\TokenProvider;
use App\Account\TokenType;
use App\Audit\AuditLog;
use App\Console\EncryptionRotateCommand;
use App\Core\Encryptor;
use App\Ingest\EventStore;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tester\Assert;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

// Rotation of APP_ENCRYPTION_KEY (specification §8.2): old data stays readable during the
// rotation and is re-encrypted with the new key, so the old key can be removed.

$database = TestDatabase::create();
$oldKey = base64_encode(random_bytes(32));
$newKey = base64_encode(random_bytes(32));
$old = new Encryptor($oldKey);
$during = new Encryptor("$newKey,$oldKey");
$new = new Encryptor($newKey);

$database->query('INSERT INTO social_accounts ?', [
	'platform' => 'facebook',
	'external_id' => 'fb:1000000000000001',
	'name' => 'Test Page',
	'status' => 'active',
]);
(new Credentials($database, $old, new AuditLog($database)))
	->replace(1, 'SYNTHETIC-TOKEN', TokenType::Page, ['pages_messaging'], null, null, operatorId: null);
for ($i = 1; $i <= 3; $i++) {
	$database->query('INSERT INTO social_events ?', [
		'platform' => 'facebook',
		'account_external_id' => 'fb:1000000000000001',
		'account_id' => 1,
		'event_key' => hash('sha256', "event-$i"),
		'topic' => 'feed',
		'payload_encrypted' => $old->encrypt("{\"synthetic\":$i}", EventStore::PayloadContext),
		'status' => 'review',
		'received_at' => new DateTimeImmutable,
	]);
}

/**
 * @param array<string, bool> $options
 */
$run = static function (Encryptor $encryptor, array $options = []) use ($database): CommandTester {
	$application = new Application;
	$application->addCommands([
		new EncryptionRotateCommand($database, $encryptor),
	]);
	$command = new CommandTester($application->find('encryption:rotate'));
	$command->execute($options);

	return $command;
};


test('dry run only reports what would be re-encrypted', function () use ($run, $during, $database, $old): void {
	$command = $run($during, ['--dry-run' => true]);
	Assert::same(0, $command->getStatusCode());
	Assert::contains("current key {$during->currentKeyId()} (dry run)", $command->getDisplay());
	Assert::contains('account_credentials.encrypted_token: 0 current, 1 to re-encrypt, 0 failed', $command->getDisplay());
	Assert::contains('social_events.payload_encrypted: 0 current, 3 to re-encrypt, 0 failed', $command->getDisplay());
	Assert::same(
		$old->currentKeyId(),
		Encryptor::ciphertextKeyId((string) $database->query('SELECT encrypted_token FROM account_credentials')->fetchField()),
	);
});


test('rotation re-encrypts everything with the new key, which then works alone', function () use ($run, $during, $new, $database): void {
	$command = $run($during);
	Assert::same(0, $command->getStatusCode());
	Assert::contains('social_events.payload_encrypted: 0 current, 3 re-encrypted, 0 failed', $command->getDisplay());

	Assert::contains('social_events.payload_encrypted: 3 current, 0 re-encrypted, 0 failed', $run($during)->getDisplay());
	Assert::same('SYNTHETIC-TOKEN', (new TokenProvider($database, $new))->token(1)->value);
	$payload = (string) $database->query('SELECT payload_encrypted FROM social_events ORDER BY id LIMIT 1')->fetchField();
	Assert::same('{"synthetic":1}', $new->decrypt($payload, EventStore::PayloadContext));
});


test('data that no configured key decrypts fails the check', function () use ($run): void {
	$command = $run(new Encryptor(base64_encode(random_bytes(32))), ['--dry-run' => true]);
	Assert::same(1, $command->getStatusCode());
	Assert::contains('social_events.payload_encrypted: 0 current, 0 to re-encrypt, 3 failed', $command->getDisplay());
	Assert::contains('4 values cannot be decrypted', $command->getDisplay());
});
