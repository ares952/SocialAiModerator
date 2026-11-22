<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Dashboard;

use App\Ingest\EventOverview;
use App\Ingest\EventStatus;
use App\Moderation\AutomationSwitch;
use App\Moderation\Policies;
use App\Monitoring\Alarms;
use App\Monitoring\Metrics;
use App\Operator\Authorization;
use App\Presentation\Admin\AdminPresenter;
use App\Queue\JobQueue;
use App\Queue\JobStatus;


/**
 * Overview: mode and panic switch, current alarms, numbers of the last 24 hours and 7 days, token
 * usage per model, events and the queue.
 */
final class DashboardPresenter extends AdminPresenter
{
	public function __construct(
		private readonly EventOverview $events,
		private readonly JobQueue $queue,
		private readonly Policies $policies,
		private readonly AutomationSwitch $automation,
		private readonly Alarms $alarms,
		private readonly Metrics $metrics,
	) {
		parent::__construct();
	}


	public function actionDefault(): void
	{
		$this->requireAllowed(Authorization::Dashboard, Authorization::View);
	}


	public function renderDefault(): void
	{
		$this->template->setParameters([
			'eventCounts' => $this->events->recentCounts(),
			'eventStatuses' => EventStatus::cases(),
			'jobCounts' => $this->queue->counts(),
			'jobStatuses' => JobStatus::cases(),
			'policy' => $this->policies->active(),
			'paused' => $this->automation->isPaused(null),
			'alarms' => $this->alarms->current(),
			'day' => $this->metrics->summary(24),
			'week' => $this->metrics->summary(24 * 7),
			'tokens' => $this->metrics->tokens(24 * 7),
		]);
	}
}
