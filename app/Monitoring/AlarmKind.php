<?php

declare(strict_types=1);

namespace App\Monitoring;


/**
 * Alarms with an editable threshold (specification §8.4); the value is in the kind's unit.
 */
enum AlarmKind: string
{
	/** Days before a token or its data access expires; an invalid, expired or missing token always alarms. */
	case TokenExpiry = 'token_expiry';

	/** Hours without any webhook of an active account that has received events before. */
	case WebhookSilence = 'webhook_silence';

	/** Minutes a due job waits in the queue, or since the worker's last heartbeat. */
	case QueueLag = 'queue_lag';

	/** Failed jobs, or failed moderation actions, within 24 hours (repeated 429/5xx and others). */
	case Failures = 'failures';

	/** Hide actions proposed or sent within 24 hours. */
	case HiddenComments = 'hidden_comments';

	/** Hours an action waits for a human approval. */
	case StaleApprovals = 'stale_approvals';

	/** Hours since the last scheduled database backup (bin/backup create); no backup at all always alarms. */
	case BackupAge = 'backup_age';


	public function max(): int
	{
		return match ($this) {
			self::TokenExpiry => 90,
			self::WebhookSilence => 720,
			self::QueueLag => 1440,
			self::Failures, self::HiddenComments => 10000,
			self::StaleApprovals, self::BackupAge => 720,
		};
	}
}
