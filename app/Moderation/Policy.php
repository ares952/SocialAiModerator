<?php

declare(strict_types=1);

namespace App\Moderation;


/**
 * One version of a moderation policy. Version 0 is the built-in default used before an admin
 * saves the first version (mode from AUTOMATION_DEFAULT_MODE).
 */
final readonly class Policy
{
	public function __construct(
		public int $version,
		public Mode $mode,
		public PolicySettings $settings,
	) {
	}
}
