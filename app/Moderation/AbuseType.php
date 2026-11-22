<?php

declare(strict_types=1);

namespace App\Moderation;


/**
 * The most serious kind of inappropriate content in a text (classification field abuse_type).
 * The strictness level of the policy decides what happens with each kind.
 */
enum AbuseType: string
{
	case None = 'none';
	case Threat = 'threat';
	case Profanity = 'profanity';
	case Insult = 'insult';
	case Ridicule = 'ridicule';
	case PassiveAggression = 'passive_aggression';


	/**
	 * Kinds that have a rule in the policy, from the most serious one.
	 *
	 * @return list<self>
	 */
	public static function ruled(): array
	{
		return [
			self::Threat,
			self::Profanity,
			self::Insult,
			self::Ridicule,
			self::PassiveAggression,
		];
	}
}
