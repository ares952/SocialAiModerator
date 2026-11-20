<?php

declare(strict_types=1);

namespace App\Ingest;

use App\Meta\Platform;


/**
 * One event item of a Meta webhook delivery, before it is stored.
 */
final readonly class IncomingEvent
{
	public function __construct(
		public Platform $platform,
		public string $accountExternalId,
		public string $topic,
		public string $eventKey,
		/** JSON with the item and its entry context; contains customer content. */
		public string $payload,
	) {
	}
}
