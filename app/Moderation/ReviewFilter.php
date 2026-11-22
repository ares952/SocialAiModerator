<?php

declare(strict_types=1);

namespace App\Moderation;


/**
 * Filter of the review queue by handover.
 */
enum ReviewFilter: string
{
	/** Handed over to the signed-in operator. */
	case Mine = 'mine';

	/** Not handed over to anyone. */
	case Unassigned = 'unassigned';
}
