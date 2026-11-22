<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Account\AccessToken;
use App\Account\AccountStatus;
use App\Account\TokenProvider;
use App\Account\TokenUnavailable;
use App\Comment\Comments;
use App\Comment\Visibility;
use App\Conversation\Direction;
use App\Core\Encryptor;
use App\Core\Logger;
use App\Meta\MetaClient;
use App\Meta\MetaFailure;
use App\Meta\MetaWriteUnavailable;
use App\Meta\Platform;
use App\Moderation\AutomationContext;
use App\Moderation\Category;
use App\Moderation\AutomationSwitch;
use App\Moderation\Policies;
use App\Moderation\PolicyEngine;
use App\Queue\Postponed;
use Nette\Database\Explorer;
use Nette\Database\Row;
use Nette\Database\SqlLiteral;
use Nette\Utils\Json;
use Nette\Utils\Strings;


/**
 * The only component that writes to Meta (job action.send, specification §2.1). It sends one
 * outbox record:
 *
 * 1. In one transaction it locks the record, re-validates the action through the PolicyEngine
 *    with the current policy, mode, panic switch, account and comment state, gets the token and
 *    marks the record `sending`. A record found in `sending` was interrupted, so its result is
 *    `unknown`. During a pause the record waits (Postponed) and is checked again later. An
 *    automatic action stopped only by rules for actions without a human (mode, automatic reply
 *    categories and limits) goes back to the approval queue instead of being blocked.
 * 2. It calls Meta (hide, unhide, the approved reply to a comment or a private message) and stores the result: `sent`,
 *    `failed`, or `unknown` when Meta may have applied it. Only a failure that surely changed
 *    nothing is retried by the queue.
 */
final class ActionDispatcher
{
	public function __construct(
		private readonly int $pauseRetrySeconds,
		private readonly Explorer $database,
		private readonly MetaClient $meta,
		private readonly TokenProvider $tokens,
		private readonly Policies $policies,
		private readonly PolicyEngine $engine,
		private readonly AutomationSwitch $switch,
		private readonly Comments $comments,
		private readonly Encryptor $encryptor,
		private readonly Actions $actions,
		private readonly Logger $logger,
	) {
	}


	/**
	 * @param bool $lastAttempt a transient failure is retried by the queue until the last attempt
	 * @throws Postponed the panic switch is on
	 * @throws MetaFailure transient failure before the last attempt; nothing was applied
	 */
	public function send(int $outboxId, bool $lastAttempt): void
	{
		$prepared = $this->database->transaction(fn() => $this->prepare($outboxId));
		if ($prepared === null) {
			return;
		}

		[$action, $token] = $prepared;
		$context = [
			'action_id' => $action->action_id,
			'outbox_id' => $outboxId,
			'type' => $action->type,
		];
		$type = ActionType::from($action->type);
		$platform = Platform::from($action->platform);
		$replyId = null;
		$target = (string) $action->external_target_id;
		$text = $type === ActionType::Reply ? $this->text($action) : null;
		try {
			if ($text === null) {
				$this->meta->hideComment($platform, $token, $target, $type === ActionType::Hide);
			} elseif ($action->message_id === null) {
				$replyId = $this->meta->replyComment($platform, $token, $target, $text);
			} else {
				$replyId = $this->meta->sendMessage($platform, $token, $target, $text, (string) $action->idempotency_key);
			}
		} catch (MetaWriteUnavailable $e) {
			$this->finish($action, OutboxStatus::Blocked, ActionStatus::Blocked, $e, [
				'meta_write_unavailable',
			]);
			$this->logger->warning('Action blocked, Meta write API not connected', $context);

			return;
		} catch (MetaFailure $e) {
			$this->failed($action, $e, $lastAttempt, $context);

			return;
		}

		$this->database->transaction(function () use ($action, $type, $replyId): void {
			$this->finish($action, OutboxStatus::Sent, ActionStatus::Sent, null, null, $replyId === null ? null : [
				'reply_id' => $replyId,
			]);
			if ($type !== ActionType::Reply) {
				$this->comments->setVisibility($action->comment_id, $type === ActionType::Hide ? Visibility::Hidden : Visibility::Visible);
			}
		});
		$this->logger->info('Action sent', $context);
	}


	/**
	 * Automatic replies of the account and of the conversation sent, being sent or with an unknown
	 * result within 24 hours (other than this one).
	 *
	 * @return array{int, int}
	 */
	private function automaticReplies(Row $action): array
	{
		$row = $this->database->query(
			'SELECT COUNT(*) AS account, SUM(m.conversation_id <=> ?) AS conversation
			FROM moderation_actions r
			LEFT JOIN social_comments c ON c.id = r.comment_id
			LEFT JOIN social_messages m ON m.id = r.message_id
			LEFT JOIN social_conversations v ON v.id = m.conversation_id
			WHERE r.type = ? AND r.automatic = 1 AND r.reviewed_by IS NULL AND r.id <> ? AND r.status IN (?)
				AND r.updated_at >= NOW(3) - INTERVAL 24 HOUR AND COALESCE(c.account_id, v.account_id) = ?',
			$action->conversation_id,
			ActionType::Reply->value,
			$action->action_id,
			[
				ActionStatus::Sending->value,
				ActionStatus::Sent->value,
				ActionStatus::Unknown->value,
			],
			$action->account_id,
		)->fetch();

		return [
			(int) $row?->account,
			$action->conversation_id === null ? 0 : (int) $row?->conversation,
		];
	}


	private function text(Row $action): string
	{
		$encrypted = $action->text_encrypted;
		if (!is_string($encrypted)) {
			throw new \LogicException('An approved reply has no text.');
		}

		return $this->encryptor->decrypt($encrypted, Actions::TextContext);
	}


	/**
	 * @return array{Row, AccessToken}|null null when there is nothing to send
	 * @throws Postponed
	 */
	private function prepare(int $outboxId): ?array
	{
		$action = $this->database->query(
			'SELECT x.id AS outbox_id, x.status AS outbox_status, x.idempotency_key, a.id AS action_id, a.type, a.automatic,
				a.decision_id, a.reviewed_by, a.external_target_id, a.text_encrypted, a.comment_id, a.message_id,
				m.conversation_id, d.category,
				COALESCE(c.account_id, v.account_id) AS account_id, s.platform, s.status AS account_status,
				c.visibility, c.own, c.text_changed_at > a.created_at AS changed,
				v.window_expires_at > NOW(3) AS window_open, v.human_takeover_at IS NOT NULL AS taken_over,
				m.deleted_at IS NOT NULL AS deleted,
				EXISTS (
					SELECT 1 FROM moderation_actions r
					WHERE r.type = ? AND r.id <> a.id AND r.status IN (?)
						AND (r.comment_id = a.comment_id OR r.message_id = a.message_id)
				) OR EXISTS (
					SELECT 1 FROM social_messages o
					WHERE o.conversation_id = m.conversation_id AND o.direction = ?
						AND COALESCE(o.sent_at, o.created_at) > COALESCE(m.sent_at, m.created_at)
				) AS replied
			FROM outbox x
			JOIN moderation_actions a ON a.id = x.action_id
			LEFT JOIN social_comments c ON c.id = a.comment_id
			LEFT JOIN social_messages m ON m.id = a.message_id
			LEFT JOIN social_conversations v ON v.id = m.conversation_id
			LEFT JOIN moderation_decisions d ON d.id = a.decision_id
			JOIN social_accounts s ON s.id = COALESCE(c.account_id, v.account_id)
			WHERE x.id = ?
			FOR UPDATE',
			ActionType::Reply->value,
			[
				ActionStatus::Sending->value,
				ActionStatus::Sent->value,
				ActionStatus::Unknown->value,
			],
			Direction::Outgoing->value,
			$outboxId,
		)->fetch();
		if ($action === null) {
			return null;
		}
		if ($action->outbox_status === OutboxStatus::Sending->value) {
			$this->finish($action, OutboxStatus::Unknown, ActionStatus::Unknown, new \RuntimeException(
				'The send was interrupted; Meta may have applied it.',
			), null);

			return null;
		}
		if ($action->outbox_status !== OutboxStatus::Pending->value) {
			return null;
		}

		$type = ActionType::from($action->type);
		$byHuman = $action->reviewed_by !== null;
		[$accountReplies, $conversationReplies] = !$byHuman && $type === ActionType::Reply
			? $this->automaticReplies($action)
			: [0, 0];
		$blocked = $this->engine->blocks(
			$type,
			$byHuman,
			$this->policies->active(),
			new AutomationContext(
				paused: $this->switch->isPaused($action->account_id),
				accountActive: $action->account_status === AccountStatus::Active->value,
				accountReplies: $accountReplies,
				conversationReplies: $conversationReplies,
			),
			$action->message_id === null
				? new ActionTarget(
					Visibility::from($action->visibility),
					(bool) $action->own,
					(bool) $action->changed,
					(bool) $action->replied,
				)
				: new ConversationTarget(
					windowOpen: (bool) $action->window_open,
					takenOver: (bool) $action->taken_over,
					drafted: $action->decision_id !== null,
					deleted: (bool) $action->deleted,
					replied: (bool) $action->replied,
				),
			$action->category === null ? null : Category::tryFrom($action->category),
		);
		if ($blocked === ['automation_paused']) {
			throw new Postponed('Automation is paused.', $this->pauseRetrySeconds);
		}
		if ($blocked !== [] && !$byHuman && array_diff($blocked, PolicyEngine::AutomationReasons) === []) {
			$this->actions->returnToHuman($action->action_id, $blocked);

			return null;
		}
		if ($blocked !== []) {
			$this->finish($action, OutboxStatus::Blocked, ActionStatus::Blocked, null, $blocked);

			return null;
		}

		try {
			$token = $this->tokens->token($action->account_id);
		} catch (TokenUnavailable $e) {
			$this->finish($action, OutboxStatus::Blocked, ActionStatus::Blocked, $e, [
				'token_unavailable',
			]);

			return null;
		}

		$this->database->query(
			'UPDATE outbox SET status = ?, attempts = attempts + 1 WHERE id = ?',
			OutboxStatus::Sending->value,
			$outboxId,
		);
		$this->database->query(
			'UPDATE moderation_actions SET status = ? WHERE id = ?',
			ActionStatus::Sending->value,
			$action->action_id,
		);

		return [
			$action,
			$token,
		];
	}


	/**
	 * @param array<string, mixed> $context
	 * @throws MetaFailure
	 */
	private function failed(Row $action, MetaFailure $e, bool $lastAttempt, array $context): void
	{
		$context += [
			'http_status' => $e->httpStatus,
			'error_code' => $e->errorCode,
		];
		if ($e->uncertain) {
			$this->finish($action, OutboxStatus::Unknown, ActionStatus::Unknown, $e, null);
			$this->logger->warning('Action result unknown', $context);

			return;
		}
		if ($e->transient && !$lastAttempt) {
			$this->finish($action, OutboxStatus::Pending, ActionStatus::Approved, $e, null);

			throw $e;
		}

		$this->finish($action, OutboxStatus::Failed, ActionStatus::Failed, $e, null);
		$this->logger->warning('Action failed', $context);
	}


	/**
	 * @param list<string>|null $reasons
	 * @param array<string, mixed>|null $result what Meta returned (the reply ID)
	 */
	private function finish(
		Row $action,
		OutboxStatus $outbox,
		ActionStatus $status,
		?\Throwable $error,
		?array $reasons,
		?array $result = null,
	): void {
		$this->database->query('UPDATE outbox SET ? WHERE id = ?', [
			'status' => $outbox->value,
			'last_error' => $error === null ? null : Strings::truncate($error::class . ': ' . $error->getMessage(), 1000),
		], $action->outbox_id);

		$values = [
			'status' => $status->value,
		];
		if ($reasons !== null) {
			$values['reasons_json'] = Json::encode($reasons);
		}
		if ($result !== null) {
			$values['result_json'] = Json::encode($result);
		}
		if ($error instanceof MetaFailure) {
			$values['result_json'] = Json::encode([
				'http_status' => $error->httpStatus,
				'error_code' => $error->errorCode,
			]);
		}
		if ($status === ActionStatus::Sent) {
			$values['executed_at'] = new SqlLiteral('NOW(3)');
		}
		$this->database->query('UPDATE moderation_actions SET ? WHERE id = ?', $values, $action->action_id);
	}
}
