<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Events;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Ingest\EventOverview;
use App\Ingest\EventStatus;
use App\Ingest\EventStore;
use App\Meta\EventType;
use App\Moderation\Decisions;
use App\Moderation\EventDecider;
use App\Operator\Authorization;
use App\Presentation\Admin\AdminPresenter;
use Nette\Utils\Paginator;


/**
 * Incoming Meta events. The list shows metadata only; the detail shows the customer content and
 * the AI explanation only to those who may work with it (moderator, admin), and every such view
 * is audited.
 */
final class EventsPresenter extends AdminPresenter
{
	private const PerPage = 50;


	public function __construct(
		private readonly EventOverview $events,
		private readonly EventStore $store,
		private readonly Decisions $decisions,
		private readonly EventDecider $decider,
		private readonly AuditLog $audit,
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


	public function actionDetail(int $id): void
	{
		$this->requireAllowed(Authorization::Events, Authorization::View);
		$event = $this->events->find($id) ?? $this->error();
		$canRead = $this->getUser()->isAllowed(Authorization::Events, Authorization::Edit);
		$decisions = $this->decisions->forEvent($id);

		$content = null;
		$explanations = [];
		if ($canRead) {
			$content = $this->store->normalized($id);
			foreach ($decisions as $decision) {
				$explanations[$decision->id] = $this->decisions->reason($decision);
			}
			$this->audit->write($this->operatorId(), AuditAction::EventContentView, 'event', $id);
		}

		$this->template->setParameters([
			'event' => $event,
			'jobs' => $this->events->jobs($id),
			'decisions' => $decisions,
			'explanations' => $explanations,
			'content' => $content,
			'canRead' => $canRead,
			'canDecide' => $canRead
				&& $event->status === EventStatus::Review->value
				&& $content?->type->contentKind() !== null
				&& $content->text !== null
				&& !$this->decisions->classified($id),
		]);
	}


	public function handleDecide(int $id): void
	{
		$this->requireAllowed(Authorization::Events, Authorization::Edit);
		if ($this->decider->request($id)) {
			$this->audit->write($this->operatorId(), AuditAction::EventDecisionRequest, 'event', $id);
			$this->flashMessage('events.detail.decide.queued', 'success');
		} else {
			$this->flashMessage('events.detail.decide.nothing', 'warning');
		}
		$this->redirect('detail', [
			'id' => $id,
		]);
	}
}
