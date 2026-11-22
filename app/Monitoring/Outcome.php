<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Outbox\ActionStatus;


/**
 * Outcome of an intervention in the charts: action statuses merged for reading, a blocked action
 * split into rejected by a moderator and stopped by the rules.
 */
enum Outcome: string
{
	case Pending = 'pending';
	case Approved = 'approved';
	case Sent = 'sent';
	case Rejected = 'rejected';
	case Blocked = 'blocked';
	case Failed = 'failed';


	public static function of(ActionStatus $status, bool $rejected): self
	{
		return match ($status) {
			ActionStatus::Pending => self::Pending,
			ActionStatus::Approved, ActionStatus::Sending => self::Approved,
			ActionStatus::Sent => self::Sent,
			ActionStatus::Blocked => $rejected ? self::Rejected : self::Blocked,
			ActionStatus::Failed, ActionStatus::Unknown => self::Failed,
		};
	}
}
