<?php

declare(strict_types=1);

namespace App\Account;

use App\Meta\Platform;
use App\Meta\TokenInfo;


/**
 * Outcome of checking one account's token; `status` is null when the check itself failed.
 */
final readonly class TokenCheckResult
{
	/**
	 * @param list<string> $missingScopes required permissions Meta did not grant
	 */
	public function __construct(
		public int $accountId,
		public string $accountName,
		public Platform $platform,
		public ?TokenStatus $status,
		public ?TokenInfo $info,
		public array $missingScopes,
		public ?string $problem,
	) {
	}


	public function ok(): bool
	{
		return $this->status === TokenStatus::Valid && $this->missingScopes === [];
	}
}
