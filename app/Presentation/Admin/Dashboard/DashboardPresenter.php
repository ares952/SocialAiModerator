<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Dashboard;

use App\Ingest\EventOverview;
use App\Ingest\EventStatus;
use App\Meta\Platform;
use App\Moderation\AutomationSwitch;
use App\Moderation\Policies;
use App\Monitoring\Alarms;
use App\Monitoring\MetricRange;
use App\Monitoring\Scope;
use App\Operator\Authorization;
use App\Presentation\Admin\AdminPresenter;
use App\Presentation\Admin\ChartExport;
use App\Presentation\Admin\ListFilters;
use App\Queue\JobQueue;
use App\Queue\JobStatus;


/**
 * Overview: mode and panic switch, current alarms, number tiles and charts of the chosen period
 * (24 hours, 7 or 30 days) for all traffic, one platform or one account, events and the queue.
 *
 * @phpstan-import-type Data from DashboardData
 */
final class DashboardPresenter extends AdminPresenter
{
	use ChartExport;
	use ListFilters;

	private MetricRange $range;

	private Scope $scope;

	/** @var Data|null */
	private ?array $data = null;


	public function __construct(
		private readonly EventOverview $events,
		private readonly JobQueue $queue,
		private readonly Policies $policies,
		private readonly AutomationSwitch $automation,
		private readonly Alarms $alarms,
		private readonly DashboardData $dashboard,
	) {
		parent::__construct();
	}


	public function actionDefault(string $range = '24h', ?string $platform = null, ?string $account = null): void
	{
		$this->requireAllowed(Authorization::Dashboard, Authorization::View);
		$this->cleanFilters([
			'platform' => $platform,
			'account' => $account,
		]);
		$this->range = MetricRange::tryFrom($range) ?? $this->error();
		$this->scope = new Scope(
			$platform === null ? null : Platform::tryFrom($platform) ?? $this->error(),
			$this->accountFilter($account),
		);
	}


	public function renderDefault(): void
	{
		$this->template->setParameters($this->data() + [
			'range' => $this->range,
			'ranges' => MetricRange::cases(),
			'scope' => $this->scope,
			'platforms' => Platform::cases(),
			'accounts' => $this->filterAccounts(),
			'eventCounts' => $this->events->recentCounts(),
			'eventStatuses' => EventStatus::cases(),
			'jobCounts' => $this->queue->counts(),
			'jobStatuses' => JobStatus::cases(),
			'policy' => $this->policies->active(),
			'paused' => $this->automation->isPaused(null),
			'alarms' => $this->alarms->current(),
		]);
	}


	protected function exportCharts(): array
	{
		return $this->data()['charts'];
	}


	/**
	 * @return Data
	 */
	private function data(): array
	{
		return $this->data ??= $this->dashboard->build($this->range, $this->scope, new \DateTimeImmutable, new \DateTimeZone($this->timezone));
	}
}
