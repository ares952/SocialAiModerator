<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Events;

use App\Ingest\EventOverview;
use App\Ingest\EventStatus;
use App\Meta\EventType;
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


	public function actionDefault(?string $status = null, ?string $type = null, int $page = 1): void
	{
		$this->requireAllowed(Authorization::Events, Authorization::View);

		$statusFilter = $status === null ? null : EventStatus::tryFrom($status) ?? $this->error();
		$typeFilter = $type === null ? null : EventType::tryFrom($type) ?? $this->error();
		$paginator = new Paginator;
		$paginator->setItemsPerPage(self::PerPage);
		$paginator->setItemCount($this->events->count($statusFilter, $typeFilter));
		$paginator->setPage($page);

		$this->template->setParameters([
			'status' => $statusFilter,
			'statuses' => EventStatus::cases(),
			'type' => $typeFilter,
			'types' => EventType::cases(),
			'paginator' => $paginator,
			'events' => $this->events->page($statusFilter, $typeFilter, $paginator->getLength(), $paginator->getOffset()),
		]);
	}
}
