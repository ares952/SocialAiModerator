<?php

declare(strict_types=1);

namespace App\Outbox;


/**
 * Interventions on a comment that write to Meta. Replies come with the ReplyGenerator; delete is
 * deliberately missing (only by an explicit rule or a human, FR-C08).
 */
enum ActionType: string
{
	/** Reversible hiding (FR-C04). */
	case Hide = 'hide';

	/** Undoes a hiding (FR-C07); only an operator requests it. */
	case Unhide = 'unhide';
}
