<?php

declare(strict_types=1);

namespace App\Moderation;


/**
 * Strictness level of the moderation (docs/moderation-strictness.md). A level only pre-fills the
 * thresholds: a saved policy version stores the values themselves, so a later change of a preset
 * never changes the rules of an existing version. Custom is the "danger zone" with free thresholds,
 * the only level that may hide mixed texts (criticism with an abusive tone).
 */
enum Strictness: string
{
	case Benevolent = 'benevolent';
	case Balanced = 'balanced';
	case Strict = 'strict';
	case Custom = 'custom';


	/**
	 * @return list<self>
	 */
	public static function presets(): array
	{
		return [
			self::Benevolent,
			self::Balanced,
			self::Strict,
		];
	}


	/**
	 * Minimum spam probability for hiding spam and scam.
	 */
	public function spamHideMin(): float
	{
		return match ($this) {
			self::Benevolent, self::Balanced => 0.95,
			self::Strict => 0.90,
			self::Custom => throw new \LogicException('The custom level has no preset.'),
		};
	}


	/**
	 * @return array<string, AbuseRule> keyed by AbuseType value, every kind of AbuseType::ruled()
	 */
	public function abuseRules(): array
	{
		return match ($this) {
			self::Benevolent => [
				AbuseType::Threat->value => new AbuseRule(0.90, 0.50),
				AbuseType::Profanity->value => new AbuseRule(null, null),
				AbuseType::Insult->value => new AbuseRule(null, 0.90),
				AbuseType::Ridicule->value => new AbuseRule(null, null),
				AbuseType::PassiveAggression->value => new AbuseRule(null, null),
			],
			self::Balanced => [
				AbuseType::Threat->value => new AbuseRule(0.85, 0.50),
				AbuseType::Profanity->value => new AbuseRule(null, 0.70),
				AbuseType::Insult->value => new AbuseRule(null, 0.70),
				AbuseType::Ridicule->value => new AbuseRule(null, null),
				AbuseType::PassiveAggression->value => new AbuseRule(null, null),
			],
			self::Strict => [
				AbuseType::Threat->value => new AbuseRule(0.80, 0.50),
				AbuseType::Profanity->value => new AbuseRule(0.85, 0.60),
				AbuseType::Insult->value => new AbuseRule(0.85, 0.60),
				AbuseType::Ridicule->value => new AbuseRule(0.95, 0.70),
				AbuseType::PassiveAggression->value => new AbuseRule(0.95, 0.80),
			],
			self::Custom => throw new \LogicException('The custom level has no preset.'),
		};
	}
}
