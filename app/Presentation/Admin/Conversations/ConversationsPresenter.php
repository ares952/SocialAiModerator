<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Conversations;

use App\Ai\ReplyGenerator;
use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Conversation\Conversations;
use App\Conversation\Inbox;
use App\Conversation\InboxFilter;
use App\Moderation\Mode;
use App\Moderation\Policies;
use App\Operator\Authorization;
use App\Outbox\Actions;
use App\Presentation\Admin\ActionReview;
use App\Presentation\Admin\AdminPresenter;
use Nette\Application\UI\Form;
use Nette\Database\Row;
use Nette\Utils\Paginator;


/**
 * Unified inbox (FR-M01, FR-M05): everyone sees the conversations with their window and takeover
 * state; moderators read the messages (audited), take a conversation over or release it, approve
 * AI reply drafts and write their own replies. Sending always goes through the outbox, which
 * checks the window again at the moment of sending.
 */
final class ConversationsPresenter extends AdminPresenter
{
	use ActionReview;

	private const PerPage = 50;

	private ?Row $conversation = null;


	public function __construct(
		private readonly Inbox $inbox,
		private readonly Conversations $conversations,
		private readonly Actions $actions,
		private readonly Policies $policies,
		private readonly AuditLog $audit,
	) {
		parent::__construct();
	}


	public function actionDefault(?string $filter = null, int $page = 1): void
	{
		$this->requireAllowed(Authorization::Conversations, Authorization::View);

		$inboxFilter = $filter === null ? null : InboxFilter::tryFrom($filter) ?? $this->error();
		$paginator = new Paginator;
		$paginator->setItemsPerPage(self::PerPage);
		$paginator->setItemCount($this->inbox->count($inboxFilter));
		$paginator->setPage($page);

		$this->template->setParameters([
			'filter' => $inboxFilter,
			'filters' => InboxFilter::cases(),
			'paginator' => $paginator,
			'conversations' => $this->inbox->page($inboxFilter, $paginator->getLength(), $paginator->getOffset()),
		]);
	}


	public function actionDetail(int $id): void
	{
		$this->requireAllowed(Authorization::Conversations, Authorization::View);
		$this->conversation = $this->inbox->find($id) ?? $this->error();
		$canRead = $this->getUser()->isAllowed(Authorization::Conversations, Authorization::Edit);
		$actions = $this->actions->forConversation($id);
		[$replies, $draft] = $this->replyTexts($actions, $canRead);

		$messages = [];
		if ($canRead) {
			$messages = $this->inbox->messages($id);
			$this->audit->write($this->operatorId(), AuditAction::ConversationContentView, Conversations::TargetType, $id);
		}

		$this->template->setParameters([
			'conversation' => $this->conversation,
			'messages' => $messages,
			'actions' => $actions,
			'replies' => $replies,
			'draft' => $draft,
			'canRead' => $canRead,
			'canAct' => $canRead && $this->getUser()->isAllowed(Authorization::Actions, Authorization::Edit),
			'observe' => $this->policies->active()->mode === Mode::Observe,
		]);
	}


	public function handleTakeover(int $id): void
	{
		$this->requireAllowed(Authorization::Conversations, Authorization::Edit);
		$this->inbox->find($id) ?? $this->error();
		$this->conversations->takeover($id, $this->operatorId());
		$this->flashMessage('conversations.taken-over', 'success');
		$this->redirect('detail', [
			'id' => $id,
		]);
	}


	public function handleRelease(int $id): void
	{
		$this->requireAllowed(Authorization::Conversations, Authorization::Edit);
		$this->inbox->find($id) ?? $this->error();
		$this->conversations->release($id, $this->operatorId());
		$this->flashMessage('conversations.released', 'success');
		$this->redirect('detail', [
			'id' => $id,
		]);
	}


	/**
	 * The operator's own reply; checked against the window again when it is sent.
	 */
	protected function createComponentMessageForm(): Form
	{
		$form = $this->forms->create();
		$form->addTextArea('text', 'conversations.message.text', rows: 3)
			->setRequired('form.error.required')
			->setMaxLength(ReplyGenerator::MaxLength);
		$form->addSubmit('send', 'conversations.message.send');

		$form->onSuccess[] = function (Form $form): void {
			$this->requireAllowed(Authorization::Conversations, Authorization::Edit);
			$id = (int) $this->getParameter('id');
			$text = (string) $form->getValues()['text'];
			$this->act($id, fn(int $operatorId) => $this->actions->message($id, $text, $operatorId), 'conversations.message.queued');
		};

		return $form;
	}


	protected function reviewedActions(): Actions
	{
		return $this->actions;
	}
}
