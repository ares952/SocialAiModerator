<?php

declare(strict_types=1);

namespace App\Meta;


/**
 * Graph API reads over HTTPS. Token checks use the app access token (META_APP_ID|META_APP_SECRET),
 * which only proves the app's identity and grants no access to account data.
 */
final class HttpGraphReader implements GraphReader
{
	public function __construct(
		private readonly GraphApi $api,
		private readonly string $appId,
		#[\SensitiveParameter]
		private readonly string $appSecret,
	) {
	}


	public function debugToken(#[\SensitiveParameter] string $token): TokenInfo
	{
		if ($this->appId === '' || $this->appSecret === '') {
			throw new MetaFailure('META_APP_ID and META_APP_SECRET must be set to check tokens.', uncertain: false, transient: false);
		}

		return TokenInfo::fromApi($this->api->get('/debug_token', [
			'input_token' => $token,
		], $this->appId . '|' . $this->appSecret));
	}
}
