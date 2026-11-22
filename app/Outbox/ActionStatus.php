<?php

declare(strict_types=1);

namespace App\Outbox;


/**
 * Lifecycle of a moderation action (specification §6).
 */
enum ActionStatus: string
{
	/** Proposed; waits for a human approval. */
	case Pending = 'pending';

	/** Approved by a human or allowed by the policy; queued in the outbox. */
	case Approved = 'approved';

	/** Rejected by a human or stopped by the check right before sending; never sent. */
	case Blocked = 'blocked';

	/** The request to Meta has started. */
	case Sending = 'sending';

	/** Meta confirmed it. */
	case Sent = 'sent';

	/** Meta refused it; nothing changed. */
	case Failed = 'failed';

	/** The result is not known (timeout, lost worker); reconciled, never retried blindly. */
	case Unknown = 'unknown';


	/**
	 * The action may still reach Meta.
	 */
	public function isOpen(): bool
	{
		return in_array($this, [self::Pending, self::Approved, self::Sending], true);
	}
}
