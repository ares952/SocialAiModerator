<?php

declare(strict_types=1);

namespace App\Moderation;

use App\Meta\Platform;


/**
 * A comment or message to moderate. The text is untrusted customer content: it goes to the AI
 * only as delimited data and is never logged.
 */
final readonly class CustomerContent
{
	public function __construct(
		public Platform $platform,
		public ContentKind $kind,
		public string $text,
		/** Earlier identical texts of the same author within the repeat window of the policy. */
		public int $repeats = 0,
	) {
	}
}
