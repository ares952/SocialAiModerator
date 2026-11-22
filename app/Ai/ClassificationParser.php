<?php

declare(strict_types=1);

namespace App\Ai;

use App\Moderation\AbuseType;
use App\Moderation\Action;
use App\Moderation\Category;
use App\Moderation\Classification;
use App\Moderation\Sentiment;
use Nette\StaticClass;


/**
 * Validates the classifier output again in PHP. Structured Outputs constrain the shape, but the
 * output is still untrusted input to the PolicyEngine.
 */
final class ClassificationParser
{
	use StaticClass;

	/**
	 * @param array<mixed> $output
	 * @param list<string> $knowledgeKeys keys of the published knowledge offered to the model; other
	 *        keys in knowledge_required are dropped, so a reply never relies on invented knowledge
	 * @throws InvalidModelOutput
	 */
	public static function parse(array $output, array $knowledgeKeys): Classification
	{
		$expected = [
			'category',
			'language',
			'sentiment',
			'confidence',
			'spam_probability',
			'abuse_type',
			'abuse_score',
			'has_substance',
			'recommended_action',
			'requires_human',
			'reason',
			'knowledge_required',
		];
		$keys = array_keys($output);
		sort($keys);
		$sorted = $expected;
		sort($sorted);
		if ($keys !== $sorted) {
			throw new InvalidModelOutput('Unexpected fields in the classification.');
		}

		$language = $output['language'];
		if (!is_string($language) || !preg_match('~^([a-z]{2}|und)$~', $language)) {
			throw new InvalidModelOutput('Invalid language.');
		}
		$reason = $output['reason'];
		if (!is_string($reason) || mb_strlen($reason) > 500) {
			throw new InvalidModelOutput('Invalid reason.');
		}
		$knowledge = $output['knowledge_required'];
		if (
			!is_array($knowledge)
			|| !array_is_list($knowledge)
			|| array_filter($knowledge, fn($key) => !is_string($key) || !preg_match('~^[a-z0-9_]{1,100}$~', $key)) !== []
		) {
			throw new InvalidModelOutput('Invalid knowledge_required.');
		}
		foreach (['requires_human', 'has_substance'] as $field) {
			if (!is_bool($output[$field])) {
				throw new InvalidModelOutput("Invalid $field.");
			}
		}

		return new Classification(
			category: Category::tryFrom(self::string($output['category'])) ?? throw new InvalidModelOutput('Invalid category.'),
			language: $language,
			sentiment: Sentiment::tryFrom(self::string($output['sentiment'])) ?? throw new InvalidModelOutput('Invalid sentiment.'),
			confidence: self::probability($output['confidence'], 'confidence'),
			spamProbability: self::probability($output['spam_probability'], 'spam_probability'),
			abuseType: AbuseType::tryFrom(self::string($output['abuse_type'])) ?? throw new InvalidModelOutput('Invalid abuse_type.'),
			abuseScore: self::probability($output['abuse_score'], 'abuse_score'),
			hasSubstance: $output['has_substance'],
			recommendedAction: Action::tryFrom(self::string($output['recommended_action']))
				?? throw new InvalidModelOutput('Invalid recommended_action.'),
			requiresHuman: $output['requires_human'],
			reason: $reason,
			knowledgeRequired: array_values(array_intersect($knowledge, $knowledgeKeys)),
		);
	}


	private static function string(mixed $value): string
	{
		return is_string($value) ? $value : '';
	}


	private static function probability(mixed $value, string $field): float
	{
		if ((!is_int($value) && !is_float($value)) || $value < 0 || $value > 1) {
			throw new InvalidModelOutput("Invalid $field.");
		}

		return (float) $value;
	}
}
