<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Comment\Visibility;
use App\Moderation\Action;
use App\Moderation\Decision;
use App\Moderation\Mode;
use App\Moderation\Policies;
use App\Queue\JobQueue;
use App\Queue\JobType;
use Nette\Database\Explorer;
use Nette\Database\Row;
use Nette\Database\SqlLiteral;
use Nette\Utils\Json;


/**
 * moderation_actions: interventions on comments proposed by an AI decision or requested by an
 * operator. An approved action gets an outbox record with its idempotency key and a send job;
 * the ActionDispatcher decides at send time whether it really goes to Meta. Nothing here calls
 * Meta. In `observe` mode operators can neither approve nor request an action, only reject it.
 */
final class Actions
{
	public const TargetType = 'moderation_action';


	public function __construct(
		private readonly Explorer $database,
		private readonly JobQueue $queue,
		private readonly Policies $policies,
		private readonly AuditLog $audit,
	) {
	}


	/**
	 * Creates the action of an AI decision about a comment: a hide waits for a human, or is queued
	 * right away when the policy allows it without one. Other decisions create no action.
	 */
	public function propose(int $eventId, int $decisionId, int $commentId, string $externalId, Decision $decision): void
	{
		if ($decision->action !== Action::Hide) {
			return;
		}

		$this->database->transaction(function () use ($eventId, $decisionId, $commentId, $externalId, $decision): void {
			$this->database->query('INSERT INTO moderation_actions ?', [
				'comment_id' => $commentId,
				'event_id' => $eventId,
				'decision_id' => $decisionId,
				'type' => ActionType::Hide->value,
				'status' => ($decision->automatic ? ActionStatus::Approved : ActionStatus::Pending)->value,
				'automatic' => $decision->automatic,
				'external_target_id' => $externalId,
			]);
			if ($decision->automatic) {
				$this->enqueue((int) $this->database->getInsertId(), ActionType::Hide);
			}
		});
	}


	/**
	 * @throws InvalidAction
	 */
	public function approve(int $actionId, int $operatorId): void
	{
		$this->database->transaction(function () use ($actionId, $operatorId): void {
			$this->requireWritableMode();
			$action = $this->pending($actionId);
			$this->review($actionId, ActionStatus::Approved, $operatorId, null);
			$this->enqueue($actionId, ActionType::from($action->type));
			$this->audit->write($operatorId, AuditAction::ActionApprove, self::TargetType, $actionId);
		});
	}


	/**
	 * @throws InvalidAction
	 */
	public function reject(int $actionId, int $operatorId): void
	{
		$this->database->transaction(function () use ($actionId, $operatorId): void {
			$this->pending($actionId);
			$this->review($actionId, ActionStatus::Blocked, $operatorId, [
				'rejected',
			]);
			$this->audit->write($operatorId, AuditAction::ActionReject, self::TargetType, $actionId);
		});
	}


	/**
	 * An operator's own hide or unhide of a comment, approved by the request itself.
	 *
	 * @throws InvalidAction
	 */
	public function request(int $commentId, ?int $eventId, ActionType $type, int $operatorId): int
	{
		return $this->database->transaction(function () use ($commentId, $eventId, $type, $operatorId): int {
			$this->requireWritableMode();
			$comment = $this->database->query(
				'SELECT external_id, visibility, own FROM social_comments WHERE id = ? FOR UPDATE',
				$commentId,
			)->fetch() ?? throw new InvalidAction('actions.error.not-found');
			$expected = $type === ActionType::Hide ? Visibility::Visible : Visibility::Hidden;
			if ($comment->visibility !== $expected->value || ($type === ActionType::Hide && $comment->own)) {
				throw new InvalidAction('actions.error.state');
			}
			if ($this->openFor($commentId)) {
				throw new InvalidAction('actions.error.open');
			}

			$this->database->query('INSERT INTO moderation_actions ?', [
				'comment_id' => $commentId,
				'event_id' => $eventId,
				'type' => $type->value,
				'status' => ActionStatus::Approved->value,
				'automatic' => false,
				'external_target_id' => $comment->external_id,
				'reviewed_by' => $operatorId,
				'reviewed_at' => new SqlLiteral('NOW(3)'),
			]);
			$actionId = (int) $this->database->getInsertId();
			$this->enqueue($actionId, $type);
			$this->audit->write($operatorId, AuditAction::ActionRequest, self::TargetType, $actionId, $type->value);

			return $actionId;
		});
	}


	/**
	 * Actions of a comment, newest first.
	 *
	 * @return list<Row>
	 */
	public function forComment(int $commentId): array
	{
		return $this->database->query(
			'SELECT a.id, a.type, a.status, a.automatic, a.reasons_json, a.result_json, a.created_at,
				a.reviewed_at, a.executed_at, o.name AS reviewed_by_name, x.last_error
			FROM moderation_actions a
			LEFT JOIN operators o ON o.id = a.reviewed_by
			LEFT JOIN outbox x ON x.action_id = a.id
			WHERE a.comment_id = ?
			ORDER BY a.id DESC',
			$commentId,
		)->fetchAll();
	}


	public function count(?ActionStatus $status): int
	{
		return (int) $this->database->query(
			'SELECT COUNT(*) FROM moderation_actions WHERE ?',
			$status === null ? [] : [
				'status' => $status->value,
			],
		)->fetchField();
	}


	/**
	 * Newest first, with the account and the event of each action; no customer content.
	 *
	 * @return list<Row>
	 */
	public function page(?ActionStatus $status, int $limit, int $offset): array
	{
		return $this->database->query(
			'SELECT a.id, a.event_id, a.type, a.status, a.automatic, a.reasons_json, a.created_at,
				a.reviewed_at, a.executed_at, o.name AS reviewed_by_name, s.platform, s.name AS account_name
			FROM moderation_actions a
			JOIN social_comments c ON c.id = a.comment_id
			JOIN social_accounts s ON s.id = c.account_id
			LEFT JOIN operators o ON o.id = a.reviewed_by
			WHERE ?
			ORDER BY a.id DESC
			LIMIT ? OFFSET ?',
			$status === null ? [] : [
				'a.status' => $status->value,
			],
			$limit,
			$offset,
		)->fetchAll();
	}


	private function openFor(int $commentId): bool
	{
		$open = array_filter(ActionStatus::cases(), fn(ActionStatus $status) => $status->isOpen());

		return (bool) $this->database->query(
			'SELECT 1 FROM moderation_actions WHERE comment_id = ? AND status IN (?) LIMIT 1',
			$commentId,
			array_map(fn(ActionStatus $status) => $status->value, $open),
		)->fetchField();
	}


	/**
	 * @throws InvalidAction
	 */
	private function pending(int $actionId): Row
	{
		$action = $this->database->query('SELECT type, status FROM moderation_actions WHERE id = ? FOR UPDATE', $actionId)->fetch()
			?? throw new InvalidAction('actions.error.not-found');
		if ($action->status !== ActionStatus::Pending->value) {
			throw new InvalidAction('actions.error.not-pending');
		}

		return $action;
	}


	/**
	 * @throws InvalidAction
	 */
	private function requireWritableMode(): void
	{
		if ($this->policies->active()->mode === Mode::Observe) {
			throw new InvalidAction('actions.error.observe');
		}
	}


	/**
	 * @param list<string>|null $reasons
	 */
	private function review(int $actionId, ActionStatus $status, int $operatorId, ?array $reasons): void
	{
		$this->database->query('UPDATE moderation_actions SET ? WHERE id = ?', [
			'status' => $status->value,
			'reasons_json' => $reasons === null ? null : Json::encode($reasons),
			'reviewed_by' => $operatorId,
			'reviewed_at' => new SqlLiteral('NOW(3)'),
		], $actionId);
	}


	private function enqueue(int $actionId, ActionType $type): void
	{
		$this->database->query('INSERT INTO outbox ?', [
			'action_id' => $actionId,
			'idempotency_key' => "$type->value:$actionId",
			'status' => OutboxStatus::Pending->value,
		]);
		$this->queue->push(JobType::SendAction, (int) $this->database->getInsertId());
	}
}
