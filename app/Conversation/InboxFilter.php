<?php

declare(strict_types=1);

namespace App\Conversation;


enum InboxFilter: string
{
	/** The standard reply window is open. */
	case Open = 'open';

	/** A reply waits for a human approval. */
	case Pending = 'pending';

	/** An operator took the conversation over. */
	case Takeover = 'takeover';
}
