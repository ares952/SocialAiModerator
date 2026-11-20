<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Events;

use App\Ingest\EventOverview;
use App\Ingest\EventStatus;
use App\Operator\Authorization;
use App\Presentation\Admin\AdminPresenter;
use Nette\Utils\Paginator;


/**
 * Incoming Meta events: metadata and processing state only, never the encrypted content.
 */
final class EventsPresenter extends AdminPresenter
{
	private const PerPage = 50;


	public function __construct(
		private readonly EventOverview $events,
	) {
		parent::__construct();
	}


	public function actionDefault(?string $status = null, int $page = 1): void
	{
		$this->requireAllowed(Authorization::Events, Authorization::View);

		$filter = $status === null ? null : EventStatus::tryFrom($status) ?? $this->error();
		$paginator = new Paginator;
		$paginator->setItemsPerPage(self::PerPage);
		$paginator->setItemCount($this->events->count($filter));
		$paginator->setPage($page);

		$this->template->setParameters([
			'status' => $filter,
			'statuses' => EventStatus::cases(),
			'paginator' => $paginator,
			'events' => $this->events->page($filter, $paginator->getLength(), $paginator->getOffset()),
		]);
	}
}
