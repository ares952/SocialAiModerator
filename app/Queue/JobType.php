<?php

declare(strict_types=1);

namespace App\Queue;


enum JobType: string
{
	/** subject_id = social_events.id */
	case ProcessEvent = 'event.process';

	/** subject_id = social_events.id; AI classification and PolicyEngine decision */
	case DecideEvent = 'event.decide';

	/** subject_id = moderation_actions.id; the ReplyGenerator writes a reply suggestion */
	case DraftReply = 'reply.draft';

	/** subject_id = outbox.id; the only job that writes to Meta (App\Outbox\ActionDispatcher) */
	case SendAction = 'action.send';

	/** subject_id = evaluation_results.id */
	case EvaluateSample = 'evaluation.sample';
}
