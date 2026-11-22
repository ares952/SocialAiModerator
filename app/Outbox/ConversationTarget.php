<?php

declare(strict_types=1);

namespace App\Outbox;


/**
 * Current state of the conversation a reply to a customer message goes to, read right before
 * sending (specification §3.5).
 */
final readonly class ConversationTarget
{
	/**
	 * @param bool $windowOpen the standard window from a verified customer message is still open
	 * @param bool $takenOver an operator took the conversation over
	 * @param bool $drafted the text comes from the ReplyGenerator (also when an operator edited it)
	 * @param bool $deleted the customer unsent the message
	 * @param bool $replied the account answered after the message (echo, another reply)
	 */
	public function __construct(
		public bool $windowOpen,
		public bool $takenOver,
		public bool $drafted,
		public bool $deleted,
		public bool $replied,
	) {
	}
}
