<?php

declare(strict_types=1);

namespace App\Account;


/**
 * A decrypted Meta access token for the Graph API client. Never log it; Tracy hides $value.
 */
final readonly class AccessToken
{
	public function __construct(
		#[\SensitiveParameter]
		public string $value,
		public TokenType $type,
	) {
	}
}
