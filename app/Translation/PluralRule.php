<?php

declare(strict_types=1);

namespace App\Translation;

use Nette\StaticClass;


/**
 * Plural forms of the supported UI languages. Form numbers match the keys in
 * data/language_<code>.json: cs uses 1, 2 (2-4) and 5 (other), en uses 1 and 2 (other).
 */
final class PluralRule
{
	use StaticClass;

	/**
	 * @return list<int>
	 */
	public static function forms(string $language): array
	{
		return match ($language) {
			'cs' => [
				1,
				2,
				5,
			],
			'en' => [
				1,
				2,
			],
			default => throw new \InvalidArgumentException("Unsupported language '$language'."),
		};
	}


	public static function form(string $language, int $count): int
	{
		$count = abs($count);

		return match ($language) {
			'cs' => match (true) {
				$count === 1 => 1,
				$count >= 2 && $count <= 4 => 2,
				default => 5,
			},
			'en' => $count === 1 ? 1 : 2,
			default => throw new \InvalidArgumentException("Unsupported language '$language'."),
		};
	}
}
