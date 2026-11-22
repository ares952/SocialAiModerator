<?php

declare(strict_types=1);

namespace App\Account;

use App\Core\Logger;
use App\Meta\GraphReader;
use App\Meta\MetaFailure;
use App\Meta\Platform;
use Nette\Database\Explorer;


/**
 * Checks the stored access tokens against Meta (GET /debug_token): valid, issued for this app,
 * and with the permissions the features need. Records the status, Meta's expiry times and granted
 * permissions; a failed check (network, rate limit) leaves the stored state as it was. Runs from
 * the worker, `meta:check` and the Accounts page. Only reads; never logs a token.
 */
final class TokenCheck
{
	public function __construct(
		private readonly Explorer $database,
		private readonly TokenProvider $tokens,
		private readonly Credentials $credentials,
		private readonly GraphReader $graph,
		private readonly Logger $logger,
		private readonly string $appId,
	) {
	}


	/**
	 * @return list<TokenCheckResult> every account with a stored token
	 */
	public function all(): array
	{
		$results = [];
		foreach ($this->database->query('SELECT social_account_id FROM account_credentials ORDER BY social_account_id') as $row) {
			$results[] = $this->account((int) $row->social_account_id);
		}

		return $results;
	}


	/**
	 * @throws TokenUnavailable the account has no token
	 */
	public function account(int $accountId): TokenCheckResult
	{
		$account = $this->database->query('SELECT name, platform FROM social_accounts WHERE id = ?', $accountId)->fetch()
			?? throw new TokenUnavailable("Account $accountId does not exist.");
		$platform = Platform::from((string) $account->platform);
		$name = (string) $account->name;

		try {
			$info = $this->graph->debugToken($this->tokens->stored($accountId)->value);
		} catch (MetaFailure $e) {
			$this->logger->warning('Token check failed', [
				'account_id' => $accountId,
				'error' => $e->getMessage(),
			]);

			return new TokenCheckResult($accountId, $name, $platform, null, null, [], $e->getMessage());
		}

		$problem = match (true) {
			!$info->valid => 'Meta reports the token as invalid' . ($info->errorCode === null ? '' : " (code $info->errorCode)") . '.',
			$this->appId !== '' && $info->appId !== $this->appId => 'The token belongs to another Meta app.',
			default => null,
		};
		$status = $problem === null ? TokenStatus::Valid : TokenStatus::Invalid;
		$this->credentials->recordCheck($accountId, $status, $info);
		$missing = $status === TokenStatus::Valid ? array_values(array_diff($platform->requiredScopes(), $info->scopes)) : [];
		if ($problem !== null || $missing !== []) {
			$this->logger->warning('Token check found a problem', [
				'account_id' => $accountId,
				'status' => $status->value,
				'missing_scopes' => implode(',', $missing),
			]);
		}

		return new TokenCheckResult($accountId, $name, $platform, $status, $info, $missing, $problem);
	}
}
