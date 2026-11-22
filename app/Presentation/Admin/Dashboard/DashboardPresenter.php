<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Dashboard;

use App\Ingest\EventOverview;
use App\Ingest\EventStatus;
use App\Moderation\AutomationSwitch;
use App\Moderation\Policies;
use App\Monitoring\Alarms;
use App\Monitoring\MetricRange;
use App\Operator\Authorization;
use App\Presentation\Admin\AdminPresenter;
use App\Queue\JobQueue;
use App\Queue\JobStatus;


/**
 * Overview: mode and panic switch, current alarms, number tiles and charts of the chosen period
 * (24 hours, 7 or 30 days), events and the queue.
 */
final class DashboardPresenter extends AdminPresenter
{
	public function __construct(
		private readonly EventOverview $events,
		private readonly JobQueue $queue,
		private readonly Policies $policies,
		private readonly AutomationSwitch $automation,
		private readonly Alarms $alarms,
		private readonly DashboardData $data,
	) {
		parent::__construct();
	}


	public function actionDefault(): void
	{
		$this->requireAllowed(Authorization::Dashboard, Authorization::View);
	}


	public function renderDefault(string $range = '24h'): void
	{
		$period = MetricRange::tryFrom($range) ?? $this->error();
		$this->template->setParameters($this->data->build($period, new \DateTimeImmutable, new \DateTimeZone($this->timezone)) + [
			'range' => $period,
			'ranges' => MetricRange::cases(),
			'eventCounts' => $this->events->recentCounts(),
			'eventStatuses' => EventStatus::cases(),
			'jobCounts' => $this->queue->counts(),
			'jobStatuses' => JobStatus::cases(),
			'policy' => $this->policies->active(),
			'paused' => $this->automation->isPaused(null),
			'alarms' => $this->alarms->current(),
		]);
	}
}
