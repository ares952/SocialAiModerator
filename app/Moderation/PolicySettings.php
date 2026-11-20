<?php

declare(strict_types=1);

namespace App\Moderation;


/**
 * Thresholds and allowed automatic actions of a policy version (moderation_policies.settings_json).
 * The defaults are the initial proposals of specification §5.4, not calibrated values: tune them
 * with the evaluation set.
 */
final readonly class PolicySettings
{
	/**
	 * @param list<Category> $autoReplyCategories categories that may be answered without a human
	 * @param list<string> $languages languages the business answers in (ISO 639-1)
	 */
	public function __construct(
		public float $hideMinConfidence = 0.95,
		public float $hideMinSpamProbability = 0.95,
		public float $replyMinConfidence = 0.90,
		public bool $autoHide = false,
		public array $autoReplyCategories = [],
		public array $languages = [
			'cs',
			'en',
			'es',
		],
		public Action $otherLanguage = Action::Handoff,
	) {
		$thresholds = [
			$hideMinConfidence,
			$hideMinSpamProbability,
			$replyMinConfidence,
		];
		foreach ($thresholds as $threshold) {
			if ($threshold < 0.5 || $threshold > 1.0) {
				throw new InvalidPolicy('policy.error.threshold');
			}
		}
		if (!in_array($otherLanguage, [Action::Handoff, Action::Review], true)) {
			throw new InvalidPolicy('policy.error.other-language');
		}
		foreach ($autoReplyCategories as $category) {
			if ($category->requiresHandoff()) {
				throw new InvalidPolicy('policy.error.handoff-category');
			}
		}
	}


	/**
	 * @param array<mixed> $data
	 * @throws InvalidPolicy
	 */
	public static function fromArray(array $data): self
	{
		$float = static fn(string $key, float $default): float => is_int($data[$key] ?? null) || is_float($data[$key] ?? null)
			? (float) $data[$key]
			: $default;
		$strings = static fn(string $key): array => array_values(array_filter(
			is_array($data[$key] ?? null) ? $data[$key] : [],
			'is_string',
		));

		$categories = [];
		foreach ($strings('auto_reply_categories') as $value) {
			$categories[] = Category::tryFrom($value) ?? throw new InvalidPolicy('policy.error.category');
		}

		$defaults = new self;

		return new self(
			hideMinConfidence: $float('hide_min_confidence', $defaults->hideMinConfidence),
			hideMinSpamProbability: $float('hide_min_spam_probability', $defaults->hideMinSpamProbability),
			replyMinConfidence: $float('reply_min_confidence', $defaults->replyMinConfidence),
			autoHide: ($data['auto_hide'] ?? false) === true,
			autoReplyCategories: $categories,
			languages: isset($data['languages']) ? $strings('languages') : $defaults->languages,
			otherLanguage: Action::tryFrom(is_string($data['other_language'] ?? null) ? $data['other_language'] : '')
				?? $defaults->otherLanguage,
		);
	}


	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'hide_min_confidence' => $this->hideMinConfidence,
			'hide_min_spam_probability' => $this->hideMinSpamProbability,
			'reply_min_confidence' => $this->replyMinConfidence,
			'auto_hide' => $this->autoHide,
			'auto_reply_categories' => array_map(fn(Category $category) => $category->value, $this->autoReplyCategories),
			'languages' => $this->languages,
			'other_language' => $this->otherLanguage->value,
		];
	}
}
