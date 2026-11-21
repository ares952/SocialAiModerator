<?php

declare(strict_types=1);

namespace App\Account;

use App\Core\Encryptor;
use Nette\Database\Explorer;


/**
 * Decrypted access token of an account for the Meta Graph API client. Reads the database on
 * every call, so a token replaced by hand applies without restarting the worker. A missing,
 * invalid or expired token is an error, never a fallback (fail closed, specification §12.9).
 */
final class TokenProvider
{
	public function __construct(
		private readonly Explorer $database,
		private readonly Encryptor $encryptor,
	) {
	}


	/**
	 * @throws TokenUnavailable
	 */
	public function token(int $accountId): AccessToken
	{
		$row = $this->database->query(
			'SELECT token_type, encrypted_token, status,
				expires_at IS NOT NULL AND expires_at <= NOW() AS expired,
				data_access_expires_at IS NOT NULL AND data_access_expires_at <= NOW() AS data_access_expired
			FROM account_credentials
			WHERE social_account_id = ?',
			$accountId,
		)->fetch();

		if ($row === null) {
			throw new TokenUnavailable("Account $accountId has no access token.");
		}

		$problem = match (true) {
			$row->status === TokenStatus::Invalid->value => 'is invalid',
			(bool) $row->expired => 'has expired',
			(bool) $row->data_access_expired => 'has no data access any more',
			default => null,
		};
		if ($problem !== null) {
			throw new TokenUnavailable("The access token of account $accountId $problem.");
		}

		return new AccessToken(
			$this->encryptor->decrypt($row->encrypted_token, Credentials::TokenContext),
			TokenType::from($row->token_type),
		);
	}
}
