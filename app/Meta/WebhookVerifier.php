<?php

declare(strict_types=1);

namespace App\Meta;


/**
 * Authenticity of Meta webhooks (specification §3.3): the subscription challenge and the
 * X-Hub-Signature-256 header. Without configured secrets no webhook can be accepted.
 */
final class WebhookVerifier
{
	public function __construct(
		#[\SensitiveParameter]
		private readonly string $appSecret,
		#[\SensitiveParameter]
		private readonly string $verifyToken,
	) {
		if ($appSecret === '' || $verifyToken === '') {
			throw new \LogicException('META_APP_SECRET and META_WEBHOOK_VERIFY_TOKEN must be set to accept Meta webhooks.');
		}
	}


	/**
	 * HMAC-SHA256 of the raw body with the App Secret, compared in constant time.
	 */
	public function isSigned(#[\SensitiveParameter] string $body, ?string $signature): bool
	{
		return $signature !== null
			&& hash_equals('sha256=' . hash_hmac('sha256', $body, $this->appSecret), $signature);
	}


	public function isSubscription(?string $mode, ?string $token): bool
	{
		return $mode === 'subscribe'
			&& $token !== null
			&& hash_equals($this->verifyToken, $token);
	}
}
