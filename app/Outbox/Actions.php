<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Ai\ReplyDraft;
use App\Ai\ReplyGenerator;
use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Comment\Visibility;
use App\Conversation\Direction;
use App\Core\Encryptor;
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
 * moderation_actions: interventions on comments and replies to customer messages, proposed by
 * an AI decision or requested by an operator. An approved action gets an outbox record with its idempotency key and a send job;
 * the ActionDispatcher decides at send time whether it really goes to Meta. Nothing here calls
 * Meta. In `observe` mode operators can neither approve nor request an action, only reject it.
 * A reply waits for its draft (job reply.draft), and a moderator may edit the text when approving;
 * the text is encrypted like customer content.
 */
final class Actions
{
	public const TargetType = 'moderation_action';
	public const TextContext = 'moderation_actions.text';

	private const DetailSql = 'SELECT a.id, a.type, a.message_id, a.status, a.automatic, a.decision_id, a.reasons_json,
			a.result_json, a.created_at, a.reviewed_at, a.executed_at, a.text_encrypted, a.edited,
			a.knowledge_json, a.model, a.prompt_version, o.name AS reviewed_by_name, x.last_error
		FROM moderation_actions a
		LEFT JOIN operators o ON o.id = a.reviewed_by
		LEFT JOIN outbox x ON x.action_id = a.id';


	public function __construct(
		private readonly Explorer $database,
		private readonly JobQueue $queue,
		private readonly Policies $policies,
		private readonly AuditLog $audit,
		private readonly Encryptor $encryptor,
	) {
	}


	/**
	 * Creates the action of an AI decision about a comment or a customer message: a hide waits for
	 * a human, or is queued right away when the policy allows it without one; a reply waits for
	 * its draft and then for a human. Other decisions create no action.
	 *
	 * @param int|null $commentId the comment, or null for a reply to the message $messageId
	 * @param string $externalId the comment, or the customer the message reply goes to
	 */
	public function propose(int $eventId, int $decisionId, ?int $commentId, ?int $messageId, string $externalId, Decision $decision): void
	{
		$type = match ($decision->action) {
			Action::Hide => ActionType::Hide,
			Action::Reply => ActionType::Reply,
			default => null,
		};
		if ($type === null || ($messageId !== null && $type !== ActionType::Reply)) {
			return;
		}

		$automatic = $type === ActionType::Hide && $decision->automatic;
		$this->database->transaction(function () use ($eventId, $decisionId, $commentId, $messageId, $externalId, $type, $automatic): void {
			$this->database->query('INSERT INTO moderation_actions ?', [
				'comment_id' => $commentId,
				'message_id' => $messageId,
				'event_id' => $eventId,
				'decision_id' => $decisionId,
				'type' => $type->value,
				'status' => ($automatic ? ActionStatus::Approved : ActionStatus::Pending)->value,
				'automatic' => $automatic,
				'external_target_id' => $externalId,
			]);
			$actionId = (int) $this->database->getInsertId();
			if ($automatic) {
				$this->enqueue($actionId, $type);
			} elseif ($type === ActionType::Reply) {
				$this->queue->push(JobType::DraftReply, $actionId);
			}
		});
	}


	/**
	 * Stores the draft of a pending reply; false when the action no longer waits for one.
	 */
	public function saveDraft(int $actionId, ReplyDraft $draft): bool
	{
		return $this->database->query(
			'UPDATE moderation_actions SET ? WHERE id = ? AND type = ? AND status = ? AND text_encrypted IS NULL',
			[
				'text_encrypted' => $this->encryptor->encrypt($draft->text, self::TextContext),
				'knowledge_json' => Json::encode($draft->knowledge),
				'model' => $draft->model,
				'prompt_version' => $draft->promptVersion,
			],
			$actionId,
			ActionType::Reply->value,
			ActionStatus::Pending->value,
		)->getRowCount() === 1;
	}


	/**
	 * Stops a pending action without a human (no draft could be written).
	 *
	 * @param list<string> $reasons
	 */
	public function block(int $actionId, array $reasons): void
	{
		$this->database->query(
			'UPDATE moderation_actions SET status = ?, reasons_json = ? WHERE id = ? AND status = ?',
			ActionStatus::Blocked->value,
			Json::encode($reasons),
			$actionId,
			ActionStatus::Pending->value,
		);
	}


	/**
	 * @param string|null $text a reply text edited by the moderator; null keeps the draft
	 * @throws InvalidAction
	 */
	public function approve(int $actionId, int $operatorId, #[\SensitiveParameter] ?string $text = null): void
	{
		$this->database->transaction(function () use ($actionId, $operatorId, $text): void {
			$this->requireWritableMode();
			$action = $this->pending($actionId);
			$type = ActionType::from($action->type);
			$edited = $type === ActionType::Reply && $this->approveText($actionId, $this->text($action), $text);
			$this->review($actionId, ActionStatus::Approved, $operatorId, null);
			$this->enqueue($actionId, $type);
			$this->audit->write($operatorId, AuditAction::ActionApprove, self::TargetType, $actionId, $edited ? 'edited' : null);
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
			if ($type === ActionType::Reply) {
				throw new InvalidAction('actions.error.state');
			}
			$comment = $this->database->query(
				'SELECT external_id, visibility, own FROM social_comments WHERE id = ? FOR UPDATE',
				$commentId,
			)->fetch() ?? throw new InvalidAction('actions.error.not-found');
			$expected = $type === ActionType::Hide ? Visibility::Visible : Visibility::Hidden;
			if ($comment->visibility !== $expected->value || ($type === ActionType::Hide && $comment->own)) {
				throw new InvalidAction('actions.error.state');
			}
			if ($this->openFor($commentId, [ActionType::Hide, ActionType::Unhide])) {
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
	 * An operator's own private message to the customer, approved by writing it. It answers the
	 * latest customer message and is still checked against the window when sent.
	 *
	 * @throws InvalidAction
	 */
	public function message(int $conversationId, #[\SensitiveParameter] string $text, int $operatorId): int
	{
		return $this->database->transaction(function () use ($conversationId, $text, $operatorId): int {
			$this->requireWritableMode();
			$text = trim($text);
			if ($text === '' || mb_strlen($text) > ReplyGenerator::MaxLength) {
				throw new InvalidAction('actions.error.text');
			}
			$message = $this->database->query(
				'SELECT m.id, c.customer_external_id
				FROM social_conversations c
				JOIN social_messages m ON m.conversation_id = c.id AND m.direction = ?
				WHERE c.id = ?
				ORDER BY m.id DESC
				LIMIT 1
				FOR UPDATE',
				Direction::Incoming->value,
				$conversationId,
			)->fetch() ?? throw new InvalidAction('actions.error.not-found');

			$this->database->query('INSERT INTO moderation_actions ?', [
				'message_id' => $message->id,
				'type' => ActionType::Reply->value,
				'status' => ActionStatus::Approved->value,
				'automatic' => false,
				'external_target_id' => $message->customer_external_id,
				'text_encrypted' => $this->encryptor->encrypt($text, self::TextContext),
				'reviewed_by' => $operatorId,
				'reviewed_at' => new SqlLiteral('NOW(3)'),
			]);
			$actionId = (int) $this->database->getInsertId();
			$this->enqueue($actionId, ActionType::Reply);
			$this->audit->write($operatorId, AuditAction::ActionRequest, self::TargetType, $actionId, 'message');

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
		return $this->database->query(self::DetailSql . ' WHERE a.comment_id = ? ORDER BY a.id DESC', $commentId)->fetchAll();
	}


	/**
	 * Replies to the messages of a conversation, newest first.
	 *
	 * @return list<Row>
	 */
	public function forConversation(int $conversationId): array
	{
		return $this->database->query(
			self::DetailSql . ' JOIN social_messages m ON m.id = a.message_id WHERE m.conversation_id = ? ORDER BY a.id DESC',
			$conversationId,
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
				a.reviewed_at, a.executed_at, o.name AS reviewed_by_name, s.platform, s.name AS account_name,
				m.conversation_id
			FROM moderation_actions a
			LEFT JOIN social_comments c ON c.id = a.comment_id
			LEFT JOIN social_messages m ON m.id = a.message_id
			LEFT JOIN social_conversations v ON v.id = m.conversation_id
			JOIN social_accounts s ON s.id = COALESCE(c.account_id, v.account_id)
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


	/**
	 * The reply text of an action row (a draft or the approved text); customer-related content.
	 */
	public function text(Row $action): ?string
	{
		return $action->text_encrypted === null
			? null
			: $this->encryptor->decrypt($action->text_encrypted, self::TextContext);
	}


	/**
	 * Stores the text the moderator approves; true when it differs from the draft.
	 *
	 * @throws InvalidAction
	 */
	private function approveText(int $actionId, ?string $draft, #[\SensitiveParameter] ?string $text): bool
	{
		$final = $text === null ? $draft : trim($text);
		if ($final === null) {
			throw new InvalidAction('actions.error.no-draft');
		}
		if ($final === '' || mb_strlen($final) > ReplyGenerator::MaxLength) {
			throw new InvalidAction('actions.error.text');
		}
		if ($final === $draft) {
			return false;
		}

		$this->database->query('UPDATE moderation_actions SET ? WHERE id = ?', [
			'text_encrypted' => $this->encryptor->encrypt($final, self::TextContext),
			'edited' => true,
		], $actionId);

		return true;
	}


	/**
	 * @param list<ActionType> $types
	 */
	private function openFor(int $commentId, array $types): bool
	{
		$open = array_filter(ActionStatus::cases(), fn(ActionStatus $status) => $status->isOpen());

		return (bool) $this->database->query(
			'SELECT 1 FROM moderation_actions WHERE comment_id = ? AND type IN (?) AND status IN (?) LIMIT 1',
			$commentId,
			array_map(fn(ActionType $type) => $type->value, $types),
			array_map(fn(ActionStatus $status) => $status->value, $open),
		)->fetchField();
	}


	/**
	 * @throws InvalidAction
	 */
	private function pending(int $actionId): Row
	{
		$action = $this->database->query('SELECT type, status, text_encrypted FROM moderation_actions WHERE id = ? FOR UPDATE', $actionId)->fetch()
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
