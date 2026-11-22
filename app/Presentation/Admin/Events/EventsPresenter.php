<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Events;

use App\Ai\ReplyGenerator;
use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Comment\Comments;
use App\Ingest\EventOverview;
use App\Ingest\EventStatus;
use App\Ingest\EventStore;
use App\Meta\EventType;
use App\Moderation\Decisions;
use App\Moderation\EventDecider;
use App\Moderation\Mode;
use App\Moderation\Policies;
use App\Outbox\Actions;
use App\Outbox\ActionType;
use App\Outbox\InvalidAction;
use App\Operator\Authorization;
use App\Presentation\Admin\AdminPresenter;
use Nette\Application\UI\Form;
use Nette\Database\Row;
use Nette\Utils\Paginator;


/**
 * Incoming Meta events. The list shows metadata only; the detail shows the customer content and
 * the AI explanation only to those who may work with it (moderator, admin), and every such view
 * is audited. On a comment's detail moderators approve or reject proposed actions and request
 * hiding or unhiding, next to the content they decide about.
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
		private readonly Comments $comments,
		private readonly Actions $actions,
		private readonly Policies $policies,
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
		$comment = $this->comment($event);
		$actions = $comment === null ? [] : $this->actions->forComment($comment->id);

		$content = null;
		$explanations = [];
		$replies = [];
		if ($canRead) {
			$content = $this->store->normalized($id);
			foreach ($decisions as $decision) {
				$explanations[$decision->id] = $this->decisions->reason($decision);
			}
			foreach ($actions as $action) {
				$replies[$action->id] = $this->actions->text($action);
			}
			$this->audit->write($this->operatorId(), AuditAction::EventContentView, 'event', $id);
		}

		// The pending reply with a draft is approved through the form, possibly edited.
		$draft = null;
		foreach ($actions as $action) {
			if ($action->type === ActionType::Reply->value && $action->status === 'pending' && ($replies[$action->id] ?? null) !== null) {
				$draft = $action->id;
				$form = $this->getComponent('replyForm');
				assert($form instanceof Form);
				$form->setDefaults([
					'action' => $action->id,
					'text' => $replies[$action->id],
				]);
				break;
			}
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
			'comment' => $comment,
			'actions' => $actions,
			'replies' => $replies,
			'draft' => $draft,
			'canAct' => $canRead && $this->getUser()->isAllowed(Authorization::Actions, Authorization::Edit),
			'observe' => $this->policies->active()->mode === Mode::Observe,
		]);
	}


	/**
	 * Approval of a reply draft, with the text as the moderator left it.
	 */
	protected function createComponentReplyForm(): Form
	{
		$form = $this->forms->create();
		$form->addHidden('action');
		$form->addTextArea('text', 'events.detail.actions.reply-text', rows: 4)
			->setRequired('form.error.required')
			->setMaxLength(ReplyGenerator::MaxLength);
		$form->addSubmit('approve', 'events.detail.actions.approve-reply');

		$form->onSuccess[] = function (Form $form): void {
			$values = $form->getValues();
			$this->act(
				(int) $this->getParameter('id'),
				fn(int $operatorId) => $this->actions->approve((int) $values['action'], $operatorId, (string) $values['text']),
				'events.detail.actions.approved',
			);
		};

		return $form;
	}


	public function handleApprove(int $id, int $action): void
	{
		$this->act($id, fn(int $operatorId) => $this->actions->approve($action, $operatorId), 'events.detail.actions.approved');
	}


	public function handleReject(int $id, int $action): void
	{
		$this->act($id, fn(int $operatorId) => $this->actions->reject($action, $operatorId), 'events.detail.actions.rejected');
	}


	public function handleRequest(int $id, string $type): void
	{
		$actionType = ActionType::tryFrom($type) ?? $this->error();
		$event = $this->events->find($id) ?? $this->error();
		$comment = $this->comment($event) ?? $this->error();
		$this->act(
			$id,
			fn(int $operatorId) => $this->actions->request($comment->id, $id, $actionType, $operatorId),
			"events.detail.actions.requested.$actionType->value",
		);
	}


	/**
	 * @param \Closure(int): mixed $change
	 */
	private function act(int $id, \Closure $change, string $message): void
	{
		$this->requireAllowed(Authorization::Events, Authorization::Edit);
		$this->requireAllowed(Authorization::Actions, Authorization::Edit);
		try {
			$change($this->operatorId());
			$this->flashMessage($message, 'success');
		} catch (InvalidAction $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}
		$this->redirect('detail', [
			'id' => $id,
		]);
	}


	/**
	 * The comment a comment event belongs to; null for other events or before the projection.
	 */
	private function comment(Row $event): ?Row
	{
		return $event->account_id !== null
			&& $event->external_id !== null
			&& str_starts_with((string) $event->event_type, 'comment.')
			? $this->comments->find($event->account_id, $event->external_id)
			: null;
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
