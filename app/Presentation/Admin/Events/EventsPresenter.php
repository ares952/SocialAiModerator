<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Events;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Comment\Comments;
use App\Conversation\Conversations;
use App\Ingest\EventOverview;
use App\Ingest\EventStatus;
use App\Ingest\EventStore;
use App\Meta\EventType;
use App\Moderation\Decisions;
use App\Moderation\EventDecider;
use App\Moderation\Mode;
use App\Moderation\Policies;
use App\Moderation\ReviewQueue;
use App\Operator\Operators;
use App\Operator\Role;
use Nette\Application\UI\Form;
use App\Outbox\Actions;
use App\Outbox\ActionType;
use App\Operator\Authorization;
use App\Presentation\Admin\ActionReview;
use App\Presentation\Admin\AdminPresenter;
use App\Presentation\Admin\ListFilters;
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
	use ActionReview;
	use ListFilters;

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
		private readonly Conversations $conversations,
		private readonly ReviewQueue $queue,
		private readonly Operators $operators,
	) {
		parent::__construct();
	}


	public function actionDefault(?string $status = null, ?string $type = null, ?string $account = null, int $page = 1): void
	{
		$this->requireAllowed(Authorization::Events, Authorization::View);

		$statusFilter = $status === null ? null : EventStatus::tryFrom($status) ?? $this->error();
		$typeFilter = $type === null ? null : EventType::tryFrom($type) ?? $this->error();
		$accountFilter = $this->accountFilter($account);
		$paginator = new Paginator;
		$paginator->setItemsPerPage(self::PerPage);
		$paginator->setItemCount($this->events->count($statusFilter, $typeFilter, $accountFilter));
		$paginator->setPage($page);

		$this->template->setParameters([
			'status' => $statusFilter,
			'statuses' => EventStatus::cases(),
			'type' => $typeFilter,
			'types' => EventType::cases(),
			'account' => $accountFilter,
			'accounts' => $this->filterAccounts(),
			'paginator' => $paginator,
			'events' => $this->events->page($statusFilter, $typeFilter, $accountFilter, $paginator->getLength(), $paginator->getOffset()),
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
		[$replies, $draft] = $this->replyTexts($actions, $canRead);

		$content = null;
		$explanations = [];
		if ($canRead) {
			$content = $this->store->normalized($id);
			foreach ($decisions as $decision) {
				$explanations[$decision->id] = $this->decisions->reason($decision);
			}
			$this->audit->write($this->operatorId(), AuditAction::EventContentView, 'event', $id);
		}

		$assign = $this->getComponent('assignForm');
		assert($assign instanceof Form);
		$assign->setDefaults([
			'assignee' => $event->assignee_id,
		]);

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
			'conversationId' => $this->conversationId($event),
			'actions' => $actions,
			'replies' => $replies,
			'draft' => $draft,
			'canAct' => $canRead && $this->getUser()->isAllowed(Authorization::Actions, Authorization::Edit),
			'queued' => $this->queue->isOpen($id),
			'next' => $this->queue->next($id),
			'observe' => $this->policies->active()->mode === Mode::Observe,
		]);
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


	protected function reviewedActions(): Actions
	{
		return $this->actions;
	}


	/**
	 * Conversation of a customer message event; null for other events or before the projection.
	 */
	private function conversationId(Row $event): ?int
	{
		$message = $event->account_id !== null && $event->external_id !== null && $event->event_type === EventType::MessageReceived->value
			? $this->conversations->message($event->account_id, $event->external_id)
			: null;

		return $message === null ? null : (int) $message->conversation_id;
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


	/**
	 * Marks the event as handled and continues with the next one in the review queue.
	 */
	public function handleResolve(int $id): void
	{
		$this->requireAllowed(Authorization::Events, Authorization::Edit);
		$this->events->find($id) ?? $this->error();
		$this->queue->resolve($id, $this->operatorId());
		$this->flashMessage('review.resolved', 'success');
		$next = $this->queue->next($id);
		$next === null
			? $this->redirect('Review:default')
			: $this->redirect('detail', [
				'id' => $next,
			]);
	}


	public function handleReopen(int $id): void
	{
		$this->requireAllowed(Authorization::Events, Authorization::Edit);
		$this->events->find($id) ?? $this->error();
		$this->queue->reopen($id, $this->operatorId());
		$this->flashMessage('review.reopened', 'success');
		$this->redirect('detail', [
			'id' => $id,
		]);
	}


	/**
	 * Hands the event over to a colleague (or takes it back).
	 */
	protected function createComponentAssignForm(): Form
	{
		$form = $this->forms->create();
		$options = [];
		foreach ($this->operators->all() as $operator) {
			if ($operator->active && $operator->role !== Role::Viewer->value) {
				$options[(int) $operator->id] = (string) $operator->name;
			}
		}
		// operator names are data, not translation keys
		$form->addSelect('assignee', $this->translator->translate('review.assignee'), $options)
			->setTranslator(null)
			->setPrompt($this->translator->translate('review.assignee.none'));
		$form->addSubmit('send', 'review.assign');

		$form->onSuccess[] = function (Form $form): void {
			$this->requireAllowed(Authorization::Events, Authorization::Edit);
			$id = (int) $this->getParameter('id');
			$this->events->find($id) ?? $this->error();
			$assignee = $form->getValues()['assignee'];
			$this->queue->assign($id, $assignee === null ? null : (int) $assignee, $this->operatorId());
			$this->flashMessage('review.assigned', 'success');
			$this->redirect('detail', [
				'id' => $id,
			]);
		};

		return $form;
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
