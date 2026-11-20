<?php

declare(strict_types=1);

namespace App\Moderation;


/**
 * Actions of the model's advice and of the PolicyEngine decision (specification §5.2). Delete is
 * deliberately missing: it happens only by an explicit rule or a human approval.
 */
enum Action: string
{
	case Ignore = 'ignore';
	case Keep = 'keep';
	case Hide = 'hide';
	case Reply = 'reply';
	case Review = 'review';
	case Handoff = 'handoff';


	/**
	 * Actions that write to Meta.
	 */
	public function isExternal(): bool
	{
		return $this === self::Hide || $this === self::Reply;
	}
}
