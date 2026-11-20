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
});
