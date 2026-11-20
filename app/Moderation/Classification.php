<?php

declare(strict_types=1);

namespace App\Moderation;


/**
 * Validated output of the classifier model. Advisory only: the PolicyEngine decides.
 */
final readonly class Classification
{
	/**
	 * @param list<string> $knowledgeRequired knowledge keys the model would need for a reply
	 */
	public function __construct(
		public Category $category,
		public string $language,
		public Sentiment $sentiment,
		public float $confidence,
		public float $spamProbability,
		public Action $recommendedAction,
		public bool $requiresHuman,
		public string $reason,
		public array $knowledgeRequired,
	) {
	}
}
