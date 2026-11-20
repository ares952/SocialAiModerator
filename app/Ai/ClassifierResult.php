<?php

declare(strict_types=1);

namespace App\Ai;

use App\Moderation\Classification;


/**
 * Classification with everything a decision must record: model, prompt version and usage.
 */
final readonly class ClassifierResult
{
	/**
	 * @param list<string> $moderationCategories
	 */
	public function __construct(
		public Classification $classification,
		public bool $moderationFlagged,
		public array $moderationCategories,
		public string $model,
		public string $promptVersion,
		public int $inputTokens,
		public int $outputTokens,
	) {
	}
}
