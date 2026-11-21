<?php

declare(strict_types=1);

namespace App\Account;


enum TokenStatus: string
{
	/** Stored or replaced, not yet checked against Meta. */
	case Unchecked = 'unchecked';

	/** The last check against Meta succeeded. */
	case Valid = 'valid';

	/** Expired, revoked or rejected by Meta; never used (fail closed). */
	case Invalid = 'invalid';
}
