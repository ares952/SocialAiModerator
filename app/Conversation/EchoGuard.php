<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Meta\NormalizedEvent;
use Nette\Database\Explorer;


/**
 * Tells the application's own sent messages from replies written by a person outside it (Meta
 * Business Suite, the Instagram app). An echo is the application's own when it carries the app ID
 * (Messenger), the outbox idempotency key as metadata, or the message ID stored after sending.
 * Anything else counts as a person's reply; mistaking an own echo for a person only stops the AI.
 */
final class EchoGuard
{
	public function __construct(
		private readonly Explorer $database,
		private readonly string $appId,
	) {
	}


	public function isOwn(NormalizedEvent $echo): bool
	{
		if ($this->appId !== '' && $echo->appId === $this->appId) {
			return true;
		}
		if ($echo->metadata !== null && $this->database->query('SELECT 1 FROM outbox WHERE idempotency_key = ?', $echo->metadata)->fetchField()) {
			return true;
		}

		return $echo->externalId !== null && (bool) $this->database->query(
			"SELECT 1 FROM moderation_actions WHERE message_id IS NOT NULL AND JSON_VALUE(result_json, '$.reply_id') = ? LIMIT 1",
			$echo->externalId,
		)->fetchField();
	}
}
