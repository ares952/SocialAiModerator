<?php

declare(strict_types=1);

namespace App\Comment;


enum Visibility: string
{
	case Visible = 'visible';

	/** Hidden on the platform; reversible (FR-C07). */
	case Hidden = 'hidden';

	/** Deleted by the author or the account; final. */
	case Removed = 'removed';
}
