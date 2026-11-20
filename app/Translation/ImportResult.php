<?php

declare(strict_types=1);

namespace App\Translation;


final readonly class ImportResult
{
	public function __construct(
		public int $changed,
		public int $removed,
		public bool $skipped = false,
	) {
	}


	public static function unchanged(): self
	{
		return new self(0, 0, skipped: true);
	}
}
