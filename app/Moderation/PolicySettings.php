<?php

declare(strict_types=1);

namespace App\Moderation;


/**
 * Thresholds, allowed automatic actions and content rules of a policy version
 * (moderation_policies.settings_json). The defaults are the initial proposals of specification
 * §5.4 and the balanced strictness level, not calibrated values: tune them with the evaluation set.
 */
final readonly class PolicySettings
{
	/** @var array<string, AbuseRule> keyed by AbuseType value, every kind of AbuseType::ruled() */
	public array $abuseRules;

	public const MaxLinksLimit = 20;
	public const MaxRepeatsLimit = 20;
	public const RepeatWindowLimit = 168;

	/** Lower-case host name with at least two labels, e.g. spam.example or xn--80ak6aa92e.com. */
	private const DomainPattern = '~^(?:[\p{Ll}\p{N}](?:[\p{Ll}\p{N}-]*[\p{Ll}\p{N}])?\.)+(?:\p{Ll}{2,}|xn--[a-z0-9-]+)$~u';

	/**
	 * @param list<Category> $autoReplyCategories categories that may be answered without a human
	 * @param list<string> $languages languages the business answers in (ISO 639-1)
	 * @param list<string> $blockedDomains lower-case spam domains; a link to them or their subdomains
	 *        is never kept or answered
	 * @param ?int $maxLinks more links than this are never kept or answered; null turns the rule off
	 * @param ?int $maxRepeats more earlier identical texts of the same author within the repeat
	 *        window are never kept or answered; null turns the rule off
	 * @param int $repeatWindowHours how far back the repeated-text rule looks
	 * @param ?array<string, AbuseRule> $abuseRules rules per kind of inappropriate content; null takes
	 *        the preset of $strictness
	 * @param ?float $mixedHideMin abuse score from which a text with a factual point is hidden; only
	 *        the custom level may set it, otherwise such a text goes at most to review
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
		public array $blockedDomains = [],
		public ?int $maxLinks = 2,
		public Strictness $strictness = Strictness::Balanced,
		?array $abuseRules = null,
		public ?float $mixedHideMin = null,
		public ?int $maxRepeats = 1,
		public int $repeatWindowHours = 24,
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
		foreach ($blockedDomains as $domain) {
			if (!preg_match(self::DomainPattern, $domain)) {
				throw new InvalidPolicy('policy.error.domain');
			}
		}
		if ($maxLinks !== null && ($maxLinks < 0 || $maxLinks > self::MaxLinksLimit)) {
			throw new InvalidPolicy('policy.error.max-links');
		}
		if ($maxRepeats !== null && ($maxRepeats < 0 || $maxRepeats > self::MaxRepeatsLimit)) {
			throw new InvalidPolicy('policy.error.max-repeats');
		}
		if ($repeatWindowHours < 1 || $repeatWindowHours > self::RepeatWindowLimit) {
			throw new InvalidPolicy('policy.error.repeat-window');
		}

		$abuseRules ??= $strictness === Strictness::Custom
			? throw new InvalidPolicy('policy.error.abuse-rules')
			: $strictness->abuseRules();
		$kinds = array_map(fn(AbuseType $type) => $type->value, AbuseType::ruled());
		$keys = array_keys($abuseRules);
		sort($kinds);
		sort($keys);
		if ($keys !== $kinds) {
			throw new InvalidPolicy('policy.error.abuse-rules');
		}
		$this->abuseRules = $abuseRules;

		if ($mixedHideMin !== null && $strictness !== Strictness::Custom) {
			throw new InvalidPolicy('policy.error.mixed-custom');
		}
		if ($mixedHideMin !== null && ($mixedHideMin < 0.5 || $mixedHideMin > 1.0)) {
			throw new InvalidPolicy('policy.error.threshold');
		}
	}


	/**
	 * The same settings with another strictness level and its thresholds.
	 *
	 * @param array<string, AbuseRule> $abuseRules
	 * @throws InvalidPolicy
	 */
	public function withStrictness(Strictness $strictness, float $spamHideMin, array $abuseRules, ?float $mixedHideMin): self
	{
		return new self(
			hideMinConfidence: $this->hideMinConfidence,
			hideMinSpamProbability: $spamHideMin,
			replyMinConfidence: $this->replyMinConfidence,
			autoHide: $this->autoHide,
			autoReplyCategories: $this->autoReplyCategories,
			languages: $this->languages,
			otherLanguage: $this->otherLanguage,
			blockedDomains: $this->blockedDomains,
			maxLinks: $this->maxLinks,
			strictness: $strictness,
			abuseRules: $abuseRules,
			mixedHideMin: $mixedHideMin,
			maxRepeats: $this->maxRepeats,
			repeatWindowHours: $this->repeatWindowHours,
		);
	}


	public function abuseRule(AbuseType $type): AbuseRule
	{
		return $this->abuseRules[$type->value] ?? throw new \LogicException("No rule for $type->value.");
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
		$strictness = Strictness::tryFrom(is_string($data['strictness'] ?? null) ? $data['strictness'] : '')
			?? (isset($data['strictness']) ? throw new InvalidPolicy('policy.error.strictness') : Strictness::Balanced);

		return new self(
			hideMinConfidence: $float('hide_min_confidence', $defaults->hideMinConfidence),
			hideMinSpamProbability: $float('hide_min_spam_probability', $defaults->hideMinSpamProbability),
			replyMinConfidence: $float('reply_min_confidence', $defaults->replyMinConfidence),
			autoHide: ($data['auto_hide'] ?? false) === true,
			autoReplyCategories: $categories,
			languages: isset($data['languages']) ? $strings('languages') : $defaults->languages,
			otherLanguage: Action::tryFrom(is_string($data['other_language'] ?? null) ? $data['other_language'] : '')
				?? $defaults->otherLanguage,
			blockedDomains: $strings('blocked_domains'),
			maxLinks: array_key_exists('max_links', $data) ? self::count($data['max_links'], 'policy.error.max-links') : $defaults->maxLinks,
			strictness: $strictness,
			abuseRules: isset($data['abuse_rules']) ? self::abuseRules($data['abuse_rules']) : null,
			mixedHideMin: self::threshold($data['mixed_hide_min'] ?? null),
			maxRepeats: array_key_exists('max_repeats', $data) ? self::count($data['max_repeats'], 'policy.error.max-repeats') : $defaults->maxRepeats,
			repeatWindowHours: array_key_exists('repeat_window_hours', $data)
				? self::count($data['repeat_window_hours'], 'policy.error.repeat-window') ?? throw new InvalidPolicy('policy.error.repeat-window')
				: $defaults->repeatWindowHours,
		);
	}


	/**
	 * @return array<string, AbuseRule>
	 * @throws InvalidPolicy
	 */
	private static function abuseRules(mixed $value): array
	{
		if (!is_array($value)) {
			throw new InvalidPolicy('policy.error.abuse-rules');
		}

		$rules = [];
		foreach ($value as $type => $rule) {
			if (!is_array($rule)) {
				throw new InvalidPolicy('policy.error.abuse-rules');
			}
			$rules[(string) $type] = new AbuseRule(
				self::threshold($rule['hide_min'] ?? null),
				self::threshold($rule['review_min'] ?? null),
			);
		}

		return $rules;
	}


	/**
	 * @throws InvalidPolicy
	 */
	private static function threshold(mixed $value): ?float
	{
		return match (true) {
			$value === null => null,
			is_int($value), is_float($value) => (float) $value,
			default => throw new InvalidPolicy('policy.error.threshold'),
		};
	}


	/**
	 * @throws InvalidPolicy
	 */
	private static function count(mixed $value, string $error): ?int
	{
		return $value === null || is_int($value)
			? $value
			: throw new InvalidPolicy($error);
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
			'blocked_domains' => $this->blockedDomains,
			'max_links' => $this->maxLinks,
			'strictness' => $this->strictness->value,
			'abuse_rules' => array_map(fn(AbuseRule $rule) => [
				'hide_min' => $rule->hideMin,
				'review_min' => $rule->reviewMin,
			], $this->abuseRules),
			'mixed_hide_min' => $this->mixedHideMin,
			'max_repeats' => $this->maxRepeats,
			'repeat_window_hours' => $this->repeatWindowHours,
		];
	}
}
