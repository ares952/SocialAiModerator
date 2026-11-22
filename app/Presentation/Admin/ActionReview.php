<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Ai\ReplyGenerator;
use App\Operator\Authorization;
use App\Outbox\Actions;
use App\Outbox\ActionStatus;
use App\Outbox\ActionType;
use App\Outbox\InvalidAction;
use Nette\Application\UI\Form;
use Nette\Database\Row;


/**
 * Approving and rejecting moderation actions next to the content they concern (event detail,
 * conversation detail; the page's action is `detail` with an `id`). A reply draft is approved
 * through a form, so the moderator can edit it first. Template: @action.latte.
 */
trait ActionReview
{
	abstract protected function reviewedActions(): Actions;


	/**
	 * Reply texts of the actions for those who may read content, and the pending draft that the
	 * reply form edits.
	 *
	 * @param list<Row> $actions
	 * @return array{array<int, string|null>, int|null}
	 */
	protected function replyTexts(array $actions, bool $canRead): array
	{
		if (!$canRead) {
			return [
				[],
				null,
			];
		}

		$texts = [];
		$draft = null;
		foreach ($actions as $action) {
			$texts[$action->id] = $this->reviewedActions()->text($action);
			if (
				$draft === null
				&& $texts[$action->id] !== null
				&& $action->type === ActionType::Reply->value
				&& $action->status === ActionStatus::Pending->value
			) {
				$draft = $action->id;
				$form = $this->getComponent('replyForm');
				assert($form instanceof Form);
				$form->setDefaults([
					'action' => $action->id,
					'text' => $texts[$action->id],
				]);
			}
		}

		return [
			$texts,
			$draft,
		];
	}


	public function handleApprove(int $id, int $action): void
	{
		$this->act($id, fn(int $operatorId) => $this->reviewedActions()->approve($action, $operatorId), 'events.detail.actions.approved');
	}


	public function handleReject(int $id, int $action): void
	{
		$this->act($id, fn(int $operatorId) => $this->reviewedActions()->reject($action, $operatorId), 'events.detail.actions.rejected');
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
				fn(int $operatorId) => $this->reviewedActions()->approve((int) $values['action'], $operatorId, (string) $values['text']),
				'events.detail.actions.approved',
			);
		};

		return $form;
	}


	/**
	 * @param \Closure(int): mixed $change
	 */
	protected function act(int $id, \Closure $change, string $message): void
	{
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
}
