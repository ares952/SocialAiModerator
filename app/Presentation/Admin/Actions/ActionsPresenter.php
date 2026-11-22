<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Actions;

use App\Operator\Authorization;
use App\Outbox\Actions;
use App\Outbox\ActionStatus;
use App\Presentation\Admin\AdminPresenter;
use Nette\Utils\Paginator;


/**
 * History of interventions on comments with their state; approving and rejecting happens on the
 * event detail next to the content (Admin:Events:detail).
 */
final class ActionsPresenter extends AdminPresenter
{
	private const PerPage = 50;


	public function __construct(
		private readonly Actions $actions,
	) {
		parent::__construct();
	}


	public function actionDefault(?string $status = null, int $page = 1): void
	{
		$this->requireAllowed(Authorization::Actions, Authorization::View);

		$filter = $status === null ? null : ActionStatus::tryFrom($status) ?? $this->error();
		$paginator = new Paginator;
		$paginator->setItemsPerPage(self::PerPage);
		$paginator->setItemCount($this->actions->count($filter));
		$paginator->setPage($page);

		$this->template->setParameters([
			'status' => $filter,
			'statuses' => ActionStatus::cases(),
			'paginator' => $paginator,
			'actions' => $this->actions->page($filter, $paginator->getLength(), $paginator->getOffset()),
		]);
	}
}
