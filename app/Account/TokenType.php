<?php

declare(strict_types=1);

namespace App\Account;


enum TokenType: string
{
	/** Page Access Token of a Facebook Page (Pages API, Messenger). */
	case Page = 'page';

	/** Business Integration System User Access Token (Instagram API with Facebook Login). */
	case SystemUser = 'system_user';
}
