<?php

declare(strict_types=1);

namespace App\Moderation;


enum ContentKind: string
{
	case Comment = 'comment';
	case Message = 'message';
}
