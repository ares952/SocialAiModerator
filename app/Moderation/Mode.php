<?php

declare(strict_types=1);

namespace App\Moderation;


enum Mode: string
{
	/** Recommendations only; never any external write (default). */
	case Observe = 'observe';

	/** Every action needs a human approval. */
	case Assisted = 'assisted';

	/** Actions the policy allows run without a human. */
	case Automatic = 'automatic';
}
