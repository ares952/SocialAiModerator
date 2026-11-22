<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Actions;

use App\Monitoring\MetricRange;
use App\Monitoring\Metrics;
use App\Monitoring\MetricSeries;
use App\Monitoring\Outcome;
use App\Monitoring\Scope;
use App\Monitoring\Timeline;
use App\Operator\Authorization;
use App\Outbox\Actions;
use App\Outbox\ActionStatus;
use App\Outbox\ActionType;
use App\Presentation\Admin\AdminPresenter;
use App\Presentation\Admin\ChartExport;
use App\Presentation\Admin\Charts;
use App\Presentation\Admin\ListFilters;
use Nette\Utils\Paginator;


/**
 * History of interventions on comments and messages with their state, filtered by status, type
 * and account, with the outcomes and stop reasons of the last 30 days as charts (type and account
 * filters apply to them as well). Approving and rejecting happens on the event detail next to the
 * content (Admin:Events:detail).
 *
 * @phpstan-import-type Chart from Charts
 */
final class ActionsPresenter extends AdminPresenter
{
	use ChartExport;
	use ListFilters;

	private const PerPage = 50;

	private ?ActionType $type = null;

	private ?int $account = null;


	public function __construct(
		private readonly Actions $actions,
		private readonly Metrics $metrics,
		private readonly MetricSeries $series,
	) {
		parent::__construct();
	}


	public function actionDefault(?string $status = null, ?string $type = null, ?string $account = null, int $page = 1): void
	{
		$this->requireAllowed(Authorization::Actions, Authorization::View);

		$statusFilter = $status === null ? null : ActionStatus::tryFrom($status) ?? $this->error();
		$this->type = $type === null ? null : ActionType::tryFrom($type) ?? $this->error();
		$this->account = $this->accountFilter($account);
		$paginator = new Paginator;
		$paginator->setItemsPerPage(self::PerPage);
		$paginator->setItemCount($this->actions->count($statusFilter, $this->type, $this->account));
		$paginator->setPage($page);

		$this->template->setParameters([
			'status' => $statusFilter,
			'statuses' => ActionStatus::cases(),
			'type' => $this->type,
			'types' => ActionType::cases(),
			'account' => $this->account,
			'accounts' => $this->filterAccounts(),
			'paginator' => $paginator,
			'actions' => $this->actions->page($statusFilter, $this->type, $this->account, $paginator->getLength(), $paginator->getOffset()),
		]);
	}


	public function renderDefault(): void
	{
		$this->template->setParameters([
			'charts' => $this->exportCharts(),
		]);
	}


	/**
	 * @return array{outcomes: Chart, reasons: Chart}
	 */
	protected function exportCharts(): array
	{
		$timeline = Timeline::create(MetricRange::Month, new \DateTimeImmutable, new \DateTimeZone($this->timezone));
		$scope = new Scope(accountId: $this->account);
		$outcomes = $this->series->outcomes($timeline, $scope, $this->type);

		return [
			'outcomes' => $this->charts->chart('bar', $this->charts->labels($timeline), array_map(
				fn(Outcome $outcome) => $this->charts->dataset("chart.outcome.{$outcome->value}", Charts::OutcomeColors[$outcome->value], $outcomes[$outcome->value]),
				Outcome::cases(),
			), [
				'stacked' => true,
			]),
			'reasons' => $this->charts->distribution('bar', $this->metrics->reasons($timeline, $scope, $this->type), 'decision.reason.'),
		];
	}
}
