<?php

declare(strict_types=1);

namespace App\Evaluation;


enum ResultStatus: string
{
	case Pending = 'pending';
	case Done = 'done';

	/** The classification failed (the live pipeline would route the item to review). */
	case Failed = 'failed';
}
