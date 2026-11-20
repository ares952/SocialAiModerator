<?php

declare(strict_types=1);

namespace App\Knowledge;


enum SourceStatus: string
{
	/** Being prepared; never used by the AI. */
	case Draft = 'draft';

	/** Approved by an admin; used within its validity. At most one per key and locale. */
	case Published = 'published';

	/** Replaced or withdrawn. */
	case Archived = 'archived';
}
