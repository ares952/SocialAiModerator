<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Dashboard;

use App\Ingest\EventOverview;
use App\Ingest\EventStatus;
use App\Moderation\AutomationSwitch;
use App\Moderation\Policies;
use App\Operator\Authorization;
use App\Presentation\Admin\AdminPresenter;
use App\Queue\JobQueue;
use App\Queue\JobStatus;


final class DashboardPresenter extends AdminPresenter
{
	public function __construct(
		private readonly EventOverview $events,
		private readonly JobQueue $queue,
		private readonly Policies $policies,
		private readonly AutomationSwitch $automation,
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
		]);
	}
}
