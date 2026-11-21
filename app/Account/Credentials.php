<?php

declare(strict_types=1);

namespace App\Account;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Core\Encryptor;
use Nette\Database\Explorer;
use Nette\Database\Row;
use Nette\Database\SqlLiteral;


/**
 * Encrypted Meta access tokens of the connected accounts (account_credentials). A token is
 * replaced by hand (console) without a restart: TokenProvider reads it from the database on
 * every use. The token itself never leaves this class except through TokenProvider.
 */
final class Credentials
{
	public const TokenContext = 'account_credentials.token';
	private const MaxTokenLength = 2000;


	public function __construct(
		private readonly Explorer $database,
		private readonly Encryptor $encryptor,
		private readonly AuditLog $audit,
	) {
	}


	/**
	 * Stores the account's token or replaces the previous one; the status returns to unchecked.
	 *
	 * @param list<string> $scopes granted permissions as shown by Meta
	 * @param \DateTimeImmutable|null $expiresAt null = never expires
	 * @param \DateTimeImmutable|null $dataAccessExpiresAt null = not known
	 * @param int|null $operatorId null = console
	 * @throws InvalidCredential
	 */
	public function replace(
		int $accountId,
		#[\SensitiveParameter]
		string $token,
		TokenType $type,
		array $scopes,
		?\DateTimeImmutable $expiresAt,
		?\DateTimeImmutable $dataAccessExpiresAt,
		?int $operatorId,
	): void {
		if ($token === '' || strlen($token) > self::MaxTokenLength || !preg_match('~^[\x21-\x7E]+$~D', $token)) {
			throw new InvalidCredential('The token must be 1-' . self::MaxTokenLength . ' printable ASCII characters without spaces.');
		}
		if ($scopes === []) {
			throw new InvalidCredential('At least one scope is required.');
		}
		foreach ($scopes as $scope) {
			if (!preg_match('~^[a-z][a-z_]{0,99}$~D', $scope)) {
				throw new InvalidCredential("Invalid scope '$scope'.");
			}
		}

		$exists = $this->database->query('SELECT 1 FROM social_accounts WHERE id = ?', $accountId)->fetchField();
		if ($exists === null) {
			throw new InvalidCredential("Account $accountId does not exist.");
		}

		$utc = new \DateTimeZone('UTC');
		$values = [
			'token_type' => $type->value,
			'encrypted_token' => $this->encryptor->encrypt($token, self::TokenContext),
			'scopes' => implode(',', array_values(array_unique($scopes))),
			'expires_at' => $expiresAt?->setTimezone($utc)->format('Y-m-d H:i:s'),
			'data_access_expires_at' => $dataAccessExpiresAt?->setTimezone($utc)->format('Y-m-d H:i:s'),
			'status' => TokenStatus::Unchecked->value,
			'checked_at' => null,
			'refreshed_at' => new SqlLiteral('NOW()'),
		];

		$this->database->transaction(function () use ($accountId, $values, $type, $expiresAt, $operatorId): void {
			$this->database->query(
				'INSERT INTO account_credentials ? ON DUPLICATE KEY UPDATE ?',
				[
					'social_account_id' => $accountId,
				] + $values,
				$values,
			);
			$this->audit->write(
				$operatorId,
				AuditAction::TokenReplace,
				'account',
				$accountId,
				"type=$type->value; scopes={$values['scopes']}; expires=" . ($expiresAt === null ? 'never' : $values['expires_at']),
			);
		});
	}


	/**
	 * Result of a validity check against Meta.
	 */
	public function recordCheck(int $accountId, TokenStatus $status): void
	{
		$this->database->query(
			'UPDATE account_credentials SET status = ?, checked_at = NOW() WHERE social_account_id = ?',
			$status->value,
			$accountId,
		);
	}


	/**
	 * Token metadata without the token, with the ID of the key it is encrypted with.
	 */
	public function find(int $accountId): ?Row
	{
		$row = $this->database->query(
			'SELECT token_type, encrypted_token, scopes, expires_at, data_access_expires_at, status, checked_at, refreshed_at
			FROM account_credentials
			WHERE social_account_id = ?',
			$accountId,
		)->fetch();
		if ($row !== null) {
			$row->key_id = Encryptor::ciphertextKeyId($row->encrypted_token);
			unset($row->encrypted_token);
		}

		return $row;
	}
}
