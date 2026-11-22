<?php

declare(strict_types=1);

namespace App\Moderation;


/**
 * A deterministic rule that matched the customer text. The value is also the decision reason code
 * (decision.reason.<value>).
 */
enum RuleHit: string
{
	case BlockedDomain = 'blocked_domain';
	case TooManyLinks = 'too_many_links';

	/** The same author sent the same text repeatedly within the repeat window. */
	case RepeatedText = 'repeated_text';
}
