<?php

declare(strict_types=1);

namespace App\Moderation;


/**
 * Classification categories (specification §5.2).
 */
enum Category: string
{
	case Spam = 'spam';
	case Scam = 'scam';
	case Abuse = 'abuse';
	case LegitimateCriticism = 'legitimate_criticism';
	case Praise = 'praise';
	case Faq = 'faq';
	case SupportIssue = 'support_issue';
	case Billing = 'billing';
	case Refund = 'refund';
	case Health = 'health';
	case Legal = 'legal';
	case Unknown = 'unknown';


	/**
	 * Complaints, billing, refunds, health and legal topics always go to a human.
	 */
	public function requiresHandoff(): bool
	{
		return in_array($this, [
			self::SupportIssue,
			self::Billing,
			self::Refund,
			self::Health,
			self::Legal,
		], true);
	}
}
