<?php

declare(strict_types=1);

namespace App\Meta;

use App\Account\AccessToken;


/**
 * Write boundary to the Meta Graph API. Only App\Outbox\ActionDispatcher may call it, and only for
 * an outbox record it has just re-validated. Production: HttpMetaClient (docs/meta-checklist.md
 * §8); tests: FakeMetaClient.
 */
interface MetaClient
{
	/**
	 * Hides or unhides a comment of the account.
	 *
	 * @param string $commentId ID with the platform prefix (Platform::externalId())
	 * @throws MetaFailure
	 */
	public function hideComment(Platform $platform, AccessToken $token, string $commentId, bool $hidden): void;


	/**
	 * Publishes a public reply to a comment of the account.
	 *
	 * @param string $commentId ID with the platform prefix (Platform::externalId())
	 * @return string ID of the reply with the platform prefix
	 * @throws MetaFailure
	 */
	public function replyComment(Platform $platform, AccessToken $token, string $commentId, #[\SensitiveParameter] string $text): string;


	/**
	 * Sends a private message to a customer inside the standard window (Messenger or Instagram
	 * Direct). Never with the HUMAN_AGENT tag.
	 *
	 * @param string $senderId the account sending (Page or Instagram account ID with the prefix)
	 * @param string $recipientId PSID or IGSID with the platform prefix
	 * @param string $metadata the outbox idempotency key, sent as the message metadata so that its
	 *        echo is recognized as the application's own (App\Conversation\EchoGuard)
	 * @return string ID of the message with the platform prefix
	 * @throws MetaFailure
	 */
	public function sendMessage(Platform $platform, AccessToken $token, string $senderId, string $recipientId, #[\SensitiveParameter] string $text, string $metadata): string;
}
