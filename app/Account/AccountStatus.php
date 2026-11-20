<?php

declare(strict_types=1);

namespace App\Account;


enum AccountStatus: string
{
	/** Events are stored and processed. */
	case Active = 'active';

	/** Events are stored as ignored, nothing is processed. */
	case Disabled = 'disabled';
}
