<?php

declare(strict_types=1);

namespace App\Conversation;


enum Direction: string
{
	/** From the customer. */
	case Incoming = 'incoming';

	/** From the account: echo of a reply by a person or by the application. */
	case Outgoing = 'outgoing';
}
