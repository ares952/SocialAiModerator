<?php

declare(strict_types=1);

namespace App\Ingest;


enum EventStatus: string
{
	/** Stored, waiting for the worker. */
	case Received = 'received';

	/** Account not connected or not active; kept for audit, never processed. */
	case Ignored = 'ignored';

	/** Needs a human; no automatic action. */
	case Review = 'review';
}
