<?php

declare(strict_types=1);

namespace App\Moderation;


/**
 * State that can block automatic actions regardless of the classification.
 */
final readonly class AutomationContext
{
	public function __construct(
		public bool $paused,
		public bool $accountActive,
	) {
	}
}
