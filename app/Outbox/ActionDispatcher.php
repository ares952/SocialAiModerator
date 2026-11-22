<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Account\AccessToken;
use App\Account\AccountStatus;
use App\Account\TokenProvider;
use App\Account\TokenUnavailable;
use App\Comment\Comments;
use App\Comment\Visibility;
use App\Core\Encryptor;
use App\Core\Logger;
use App\Meta\MetaClient;
use App\Meta\MetaFailure;
use App\Meta\MetaWriteUnavailable;
use App\Meta\Platform;
use App\Moderation\AutomationContext;
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
 *    `unknown`. During a pause the record waits (Postponed) and is checked again later.
 * 2. It calls Meta (hide, unhide or the approved reply text) and stores the result: `sent`,
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
		try {
			if ($type === ActionType::Reply) {
				$replyId = $this->meta->replyComment(
					$platform,
					$token,
					$action->external_target_id,
					$this->encryptor->decrypt(
						$action->text_encrypted ?? throw new \LogicException('An approved reply has no text.'),
						Actions::TextContext,
					),
				);
			} else {
				$this->meta->hideComment($platform, $token, $action->external_target_id, $type === ActionType::Hide);
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
	 * @return array{Row, AccessToken}|null null when there is nothing to send
	 * @throws Postponed
	 */
	private function prepare(int $outboxId): ?array
	{
		$action = $this->database->query(
			'SELECT x.id AS outbox_id, x.status AS outbox_status, a.id AS action_id, a.type, a.automatic,
				a.external_target_id, a.text_encrypted, a.comment_id, c.account_id, c.visibility, c.own,
				c.text_changed_at > a.created_at AS changed, s.platform, s.status AS account_status,
				EXISTS (
					SELECT 1 FROM moderation_actions r
					WHERE r.comment_id = a.comment_id AND r.type = ? AND r.id <> a.id AND r.status IN (?)
				) AS replied
			FROM outbox x
			JOIN moderation_actions a ON a.id = x.action_id
			JOIN social_comments c ON c.id = a.comment_id
			JOIN social_accounts s ON s.id = c.account_id
			WHERE x.id = ?
			FOR UPDATE',
			ActionType::Reply->value,
			[
				ActionStatus::Sending->value,
				ActionStatus::Sent->value,
				ActionStatus::Unknown->value,
			],
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

		$blocked = $this->engine->blocks(
			ActionType::from($action->type),
			!$action->automatic,
			$this->policies->active(),
			new AutomationContext(
				paused: $this->switch->isPaused($action->account_id),
				accountActive: $action->account_status === AccountStatus::Active->value,
			),
			new ActionTarget(
				Visibility::from($action->visibility),
				(bool) $action->own,
				(bool) $action->changed,
				(bool) $action->replied,
			),
		);
		if ($blocked === ['automation_paused']) {
			throw new Postponed('Automation is paused.', $this->pauseRetrySeconds);
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
