<?php

declare(strict_types=1);

namespace App\Meta;


/**
 * What happened, independent of the platform-specific webhook structure (EventNormalizer).
 */
enum EventType: string
{
	case CommentCreated = 'comment.created';
	case CommentEdited = 'comment.edited';
	case CommentRemoved = 'comment.removed';
	case CommentHidden = 'comment.hidden';
	case CommentUnhidden = 'comment.unhidden';

	/** Message from a customer. */
	case MessageReceived = 'message.received';

	/** Copy of a message sent by the account itself; never triggers a reply. */
	case MessageEcho = 'message.echo';

	/** Message unsent by the customer. */
	case MessageDeleted = 'message.deleted';
	case Postback = 'postback';

	/** Recognized, but not relevant for moderation: reactions, read receipts, own posts. */
	case Other = 'other';

	/** Structure not recognized; a human has to look at it. */
	case Unsupported = 'unsupported';
}
