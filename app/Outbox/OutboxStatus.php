<?php

declare(strict_types=1);

namespace App\Outbox;


/**
 * Delivery state of an outbox record; the action carries the same outcome for the administration.
 */
enum OutboxStatus: string
{
	/** Waits for the ActionDispatcher (also while the panic switch is on). */
	case Pending = 'pending';

	/** A send has started and its result is not stored yet. */
	case Sending = 'sending';

	case Sent = 'sent';
	case Failed = 'failed';
	case Unknown = 'unknown';

	/** Stopped by the check right before sending. */
	case Blocked = 'blocked';
}
