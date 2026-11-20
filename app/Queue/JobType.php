<?php

declare(strict_types=1);

namespace App\Queue;


enum JobType: string
{
	/** subject_id = social_events.id */
	case ProcessEvent = 'event.process';

	/** subject_id = evaluation_results.id */
	case EvaluateSample = 'evaluation.sample';
}
