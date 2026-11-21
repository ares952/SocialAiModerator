<?php

declare(strict_types=1);

namespace App\Retention;


/**
 * Kinds of stored data with their own retention period (specification §13, item 8).
 */
enum RetentionData: string
{
	/** Comment events with their AI decisions. */
	case Comments = 'comments';

	/** Message events (incoming, echo, unsent, postbacks) with their AI decisions. */
	case Messages = 'messages';

	/** Everything else from Meta: reactions, read receipts, unsupported and ignored events. */
	case OtherEvents = 'other_events';

	/** The administration audit log. */
	case Audit = 'audit';
}
