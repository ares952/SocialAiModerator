<?php

declare(strict_types=1);

namespace App\Queue;


enum JobStatus: string
{
	/** Waiting until available_at. */
	case Pending = 'pending';

	/** A worker holds it until reserved_until. */
	case Reserved = 'reserved';

	case Done = 'done';

	/** All attempts failed; needs a human. */
	case Failed = 'failed';
}
