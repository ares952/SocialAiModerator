<?php

declare(strict_types=1);

namespace App\Moderation;


/**
 * Validated output of the classifier model. Advisory only: the PolicyEngine decides.
 */
final readonly class Classification
{
	/**
	 * @param float $abuseScore how clearly the text contains $abuseType, 0 to 1
	 * @param bool $hasSubstance the text contains a factual objection to the product or service
	 * @param list<string> $knowledgeRequired published knowledge keys the model would need for a reply
	 */
	public function __construct(
		public Category $category,
		public string $language,
		public Sentiment $sentiment,
		public float $confidence,
		public float $spamProbability,
		public AbuseType $abuseType,
		public float $abuseScore,
		public bool $hasSubstance,
		public Action $recommendedAction,
		public bool $requiresHuman,
		public string $reason,
		public array $knowledgeRequired,
	) {
	}
}
