<?php

declare(strict_types=1);

namespace App\Core;


/**
 * Authenticated encryption at rest (XChaCha20-Poly1305, libsodium) with APP_ENCRYPTION_KEY.
 * The context (e.g. `social_events.payload`) is bound as associated data, so a ciphertext copied
 * to another column does not decrypt.
 *
 * Key rotation (specification §8.2): APP_ENCRYPTION_KEY is a comma-separated list of base64 keys.
 * The first one encrypts; the others only decrypt until `bin/console encryption:rotate` has
 * re-encrypted all data. Every ciphertext names its key by a key ID (fingerprint of the key).
 *
 * Format 2: 0x02, key ID (4 bytes), nonce, ciphertext with tag.
 * Format 1 (before key IDs): 0x01, nonce, ciphertext with tag; tried with every configured key.
 */
final class Encryptor
{
	private const Version = "\x02";
	private const LegacyVersion = "\x01";
	private const KeyIdLength = 4;
	private const NonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

	/** @var array<string, string> key ID => key, the current key first */
	private readonly array $keys;

	private readonly string $currentKeyId;


	public function __construct(#[\SensitiveParameter] string $keys)
	{
		$decodedKeys = [];
		foreach (explode(',', $keys) as $key) {
			$decoded = base64_decode(trim($key), strict: true);
			if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
				throw new \LogicException('APP_ENCRYPTION_KEY must be a comma-separated list of 32-byte keys encoded in base64.');
			}

			$keyId = self::keyIdOf($decoded);
			if (isset($decodedKeys[$keyId])) {
				throw new \LogicException('APP_ENCRYPTION_KEY contains the same key twice.');
			}

			$decodedKeys[$keyId] = $decoded;
		}

		$this->keys = $decodedKeys;
		$this->currentKeyId = (string) array_key_first($decodedKeys);
	}


	public function encrypt(#[\SensitiveParameter] string $plaintext, string $context): string
	{
		$nonce = random_bytes(self::NonceLength);

		return self::Version
			. $this->currentKeyId
			. $nonce
			. sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $context, $nonce, $this->keys[$this->currentKeyId]);
	}


	/**
	 * @throws DecryptionFailed
	 */
	public function decrypt(string $ciphertext, string $context): string
	{
		$plaintext = false;
		if (str_starts_with($ciphertext, self::Version) && strlen($ciphertext) > 1 + self::KeyIdLength + self::NonceLength) {
			$key = $this->keys[substr($ciphertext, 1, self::KeyIdLength)] ?? null;
			if ($key !== null) {
				$plaintext = self::open(substr($ciphertext, 1 + self::KeyIdLength), $context, $key);
			}
		} elseif (str_starts_with($ciphertext, self::LegacyVersion) && strlen($ciphertext) > 1 + self::NonceLength) {
			foreach ($this->keys as $key) {
				$plaintext = self::open(substr($ciphertext, 1), $context, $key);
				if ($plaintext !== false) {
					break;
				}
			}
		}

		if ($plaintext === false) {
			throw new DecryptionFailed("Cannot decrypt $context: unknown format, unknown key or modified data.");
		}

		return $plaintext;
	}


	/**
	 * Whether the ciphertext is encrypted with an older key or format and should be re-encrypted.
	 */
	public function isOutdated(string $ciphertext): bool
	{
		return !str_starts_with($ciphertext, self::Version . $this->currentKeyId);
	}


	/**
	 * Hex key ID of the current key; shown in the administration and in rotation output, never
	 * the key itself.
	 */
	public function currentKeyId(): string
	{
		return bin2hex($this->currentKeyId);
	}


	/**
	 * Hex key ID a ciphertext was encrypted with; null for format 1 or an unknown format.
	 */
	public static function ciphertextKeyId(string $ciphertext): ?string
	{
		return str_starts_with($ciphertext, self::Version) && strlen($ciphertext) > 1 + self::KeyIdLength
			? bin2hex(substr($ciphertext, 1, self::KeyIdLength))
			: null;
	}


	/**
	 * Fingerprint of a key; a one-way hash, so it reveals nothing about the key.
	 */
	private static function keyIdOf(#[\SensitiveParameter] string $key): string
	{
		return substr(sodium_crypto_generichash($key, '', 16), 0, self::KeyIdLength);
	}


	private static function open(string $sealed, string $context, #[\SensitiveParameter] string $key): string|false
	{
		return sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
			substr($sealed, self::NonceLength),
			$context,
			substr($sealed, 0, self::NonceLength),
			$key,
		);
	}
}
