<?php

declare(strict_types=1);

namespace App\Meta;

use App\Account\AccessToken;


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


	public function comment(Platform $platform, AccessToken $token, string $commentId): CommentInfo
	{
		$prefix = $platform->externalId('');
		if (!str_starts_with($commentId, $prefix)) {
			throw new \InvalidArgumentException("Comment ID must start with $prefix.");
		}

		$id = substr($commentId, strlen($prefix));
		if (preg_match('~^[0-9_]+$~D', $id) !== 1) {
			throw new \InvalidArgumentException('Comment ID must be numeric.');
		}

		return CommentInfo::fromApi($platform, $this->api->get('/' . $id, [
			'fields' => match ($platform) {
				Platform::Facebook => 'id,message,from,created_time,is_hidden,can_hide,parent',
				Platform::Instagram => 'id,text,timestamp,username,hidden,from,parent_id,media{id}',
			},
		], $token->value));
	}
}
