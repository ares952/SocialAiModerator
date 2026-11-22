<?php

declare(strict_types=1);

namespace App\Ingest;


enum EventStatus: string
{
	/** Stored, waiting for the worker. */
	case Received = 'received';

	/**
	 * Account not connected or not active (never processed), an event without anything to
	 * moderate (App\Meta\EventType::Other), or the account's own comment or message echo;
	 * kept for audit.
	 */
	case Ignored = 'ignored';

	/** Needs a human; no automatic action. */
	case Review = 'review';
}
