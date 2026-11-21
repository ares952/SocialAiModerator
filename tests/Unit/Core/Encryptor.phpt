<?php

declare(strict_types=1);

use App\Core\DecryptionFailed;
use App\Core\Encryptor;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$encryptor = new Encryptor(base64_encode(random_bytes(32)));


test('ciphertext decrypts back and does not contain the plaintext', function () use ($encryptor): void {
	$ciphertext = $encryptor->encrypt('Dobrý den, kolik stojí kurz?', 'social_events.payload');
	Assert::notContains('kurz', $ciphertext);
	Assert::same('Dobrý den, kolik stojí kurz?', $encryptor->decrypt($ciphertext, 'social_events.payload'));
});


test('each encryption uses a new nonce', function () use ($encryptor): void {
	Assert::notSame($encryptor->encrypt('same', 'ctx'), $encryptor->encrypt('same', 'ctx'));
});


test('other context, other key or modified data do not decrypt', function () use ($encryptor): void {
	$ciphertext = $encryptor->encrypt('secret', 'social_events.payload');
	$modified = substr($ciphertext, 0, -1) . chr(ord($ciphertext[-1]) ^ 1);

	Assert::exception(fn() => $encryptor->decrypt($ciphertext, 'social_messages.text'), DecryptionFailed::class);
	Assert::exception(fn() => $encryptor->decrypt($modified, 'social_events.payload'), DecryptionFailed::class);
	Assert::exception(fn() => $encryptor->decrypt('', 'social_events.payload'), DecryptionFailed::class);
	Assert::exception(
		fn() => (new Encryptor(base64_encode(random_bytes(32))))->decrypt($ciphertext, 'social_events.payload'),
		DecryptionFailed::class,
	);
});


test('key must be 32 bytes in base64', function (): void {
	Assert::exception(fn() => new Encryptor(''), LogicException::class);
	Assert::exception(fn() => new Encryptor(base64_encode(random_bytes(16))), LogicException::class);
	Assert::exception(fn() => new Encryptor('not base64!'), LogicException::class);
	Assert::exception(fn() => new Encryptor(base64_encode(random_bytes(32)) . ','), LogicException::class);
});


test('the same key twice is a configuration error', function (): void {
	$key = base64_encode(random_bytes(32));
	Assert::exception(fn() => new Encryptor("$key,$key"), LogicException::class, '%a%same key twice%a%');
});


test('ciphertext names its key; a rotated key decrypts old data until it is re-encrypted', function (): void {
	$old = base64_encode(random_bytes(32));
	$new = base64_encode(random_bytes(32));
	$before = new Encryptor($old);
	$during = new Encryptor("$new, $old");
	$after = new Encryptor($new);

	$ciphertext = $before->encrypt('token', 'account_credentials.token');
	Assert::same($before->currentKeyId(), Encryptor::ciphertextKeyId($ciphertext));
	Assert::match('%h%', $before->currentKeyId());
	Assert::same(8, strlen($before->currentKeyId()));
	Assert::false($before->isOutdated($ciphertext));

	Assert::same('token', $during->decrypt($ciphertext, 'account_credentials.token'));
	Assert::true($during->isOutdated($ciphertext));
	$reencrypted = $during->encrypt($during->decrypt($ciphertext, 'account_credentials.token'), 'account_credentials.token');
	Assert::false($during->isOutdated($reencrypted));
	Assert::same($after->currentKeyId(), Encryptor::ciphertextKeyId($reencrypted));

	Assert::same('token', $after->decrypt($reencrypted, 'account_credentials.token'));
	Assert::exception(fn() => $after->decrypt($ciphertext, 'account_credentials.token'), DecryptionFailed::class);
});


test('format 1 without key ID still decrypts with any configured key and is outdated', function (): void {
	$old = random_bytes(32);
	$nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
	$legacy = "\x01" . $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt('payload', 'social_events.payload', $nonce, $old);

	$encryptor = new Encryptor(base64_encode(random_bytes(32)) . ',' . base64_encode($old));
	Assert::same('payload', $encryptor->decrypt($legacy, 'social_events.payload'));
	Assert::true($encryptor->isOutdated($legacy));
	Assert::null(Encryptor::ciphertextKeyId($legacy));
	Assert::exception(
		fn() => (new Encryptor(base64_encode(random_bytes(32))))->decrypt($legacy, 'social_events.payload'),
		DecryptionFailed::class,
	);
});
