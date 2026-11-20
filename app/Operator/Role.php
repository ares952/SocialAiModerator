<?php

declare(strict_types=1);

namespace App\Operator;


enum Role: string
{
	/** Everything, including operators, accounts, policies, modes and the panic switch. */
	case Admin = 'admin';

	/** Review, approvals, comment visibility, conversation takeover, DM content. */
	case Moderator = 'moderator';

	/** Dashboard, statistics and lists without DM content; no actions. */
	case Viewer = 'viewer';


	/**
	 * Translation key of the name.
	 */
	public function label(): string
	{
		return 'operator.role.' . $this->value;
	}
}
