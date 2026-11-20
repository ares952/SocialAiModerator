<?php

declare(strict_types=1);

namespace App\Core;


/**
 * Authenticated encryption at rest (XChaCha20-Poly1305, libsodium) with APP_ENCRYPTION_KEY.
 * The context (e.g. `social_events.payload`) is bound as associated data, so a ciphertext copied
 * to another column does not decrypt. Format: version byte, nonce, ciphertext with tag.
 */
final class Encryptor
{
	private const Version = "\x01";
	private const NonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

	private readonly string $key;


	public function __construct(#[\SensitiveParameter] string $key)
	{
		$decoded = base64_decode($key, strict: true);
		if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
			throw new \LogicException('APP_ENCRYPTION_KEY must be 32 bytes encoded in base64.');
		}

		$this->key = $decoded;
	}


	public function encrypt(#[\SensitiveParameter] string $plaintext, string $context): string
	{
		$nonce = random_bytes(self::NonceLength);

		return self::Version
			. $nonce
			. sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $context, $nonce, $this->key);
	}


	/**
	 * @throws DecryptionFailed
	 */
	public function decrypt(string $ciphertext, string $context): string
	{
		$plaintext = false;
		if (
			str_starts_with($ciphertext, self::Version)
			&& strlen($ciphertext) > 1 + self::NonceLength
		) {
			$plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
				substr($ciphertext, 1 + self::NonceLength),
				$context,
				substr($ciphertext, 1, self::NonceLength),
				$this->key,
			);
		}

		if ($plaintext === false) {
			throw new DecryptionFailed("Cannot decrypt $context: unknown format, wrong key or modified data.");
		}

		return $plaintext;
	}
}
