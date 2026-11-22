<?php

declare(strict_types=1);

namespace App\Moderation;


/**
 * State that can block automatic actions regardless of the classification.
 */
final readonly class AutomationContext
{
	/**
	 * @param int $accountReplies automatic replies of the account sent (or possibly sent) in 24 hours
	 * @param int $conversationReplies the same within the conversation of the reply
	 */
	public function __construct(
		public bool $paused,
		public bool $accountActive,
		public int $accountReplies = 0,
		public int $conversationReplies = 0,
	) {
	}
}
