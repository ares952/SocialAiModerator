<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Core\Encryptor;
use App\Meta\EventType;
use App\Meta\NormalizedEvent;
use Nette\Database\Explorer;
use Nette\Database\Row;
use Nette\Database\SqlLiteral;


/**
 * Conversations and their messages, projected from message events (specification §3.5, FR-M02).
 * A conversation belongs to one account and one customer. Only a customer message with a time
 * verified by Meta moves the reply window; an echo of the account's own message never does.
 * A message unsent by the customer loses its text, also when the unsend arrives first.
 * The projection is idempotent. Runs inside the transaction of the event processing.
 * An operator takes a conversation over and releases it explicitly (FR-M05); while it is taken
 * over, no AI reply is drafted or sent.
 */
final class Conversations
{
	public const TextContext = 'social_messages.text';

	/** Standard messaging window of Meta, counted from the latest customer message. */
	public const WindowHours = 24;

	/** Upper bound of texts read for the repeated-text rule; protects the worker, not a policy. */
	private const RecentLimit = 50;


	/** Earlier messages given to the ReplyGenerator as context (FR-M03); kept small (data minimization). */
	public const HistoryLimit = 6;

	public const TargetType = 'conversation';


	public function __construct(
		private readonly Explorer $database,
		private readonly Encryptor $encryptor,
		private readonly AuditLog $audit,
	) {
	}


	/**
	 * Customer message of the account by its external ID, with its conversation; null before the
	 * projection.
	 */
	public function message(int $accountId, string $externalId): ?Row
	{
		return $this->database->query(
			'SELECT m.id, m.conversation_id, c.customer_external_id
			FROM social_messages m
			JOIN social_conversations c ON c.id = m.conversation_id
			WHERE c.account_id = ? AND m.external_id = ? AND m.direction = ?',
			$accountId,
			$externalId,
			Direction::Incoming->value,
		)->fetch();
	}


	/**
	 * Text of a message; null when it has none or was unsent. Customer content.
	 */
	public function text(int $messageId): ?string
	{
		$text = $this->database->query('SELECT text_encrypted FROM social_messages WHERE id = ?', $messageId)->fetchField();

		return is_string($text) ? $this->encryptor->decrypt($text, self::TextContext) : null;
	}


	/**
	 * Messages of the conversation before the given one, oldest first, at most HistoryLimit.
	 *
	 * @return list<array{direction: string, text: string}>
	 */
	public function history(int $messageId): array
	{
		$rows = $this->database->query(
			'SELECT m.direction, m.text_encrypted
			FROM social_messages m
			JOIN social_messages target ON target.conversation_id = m.conversation_id
			WHERE target.id = ? AND m.id < target.id AND m.text_encrypted IS NOT NULL
			ORDER BY m.id DESC
			LIMIT ?',
			$messageId,
			self::HistoryLimit,
		)->fetchAll();

		return array_reverse(array_map(fn(Row $row) => [
			'direction' => (string) $row->direction,
			'text' => $this->encryptor->decrypt($row->text_encrypted, self::TextContext),
		], $rows));
	}


	public function isTakenOver(int $conversationId): bool
	{
		return (bool) $this->database->query(
			'SELECT human_takeover_at IS NOT NULL FROM social_conversations WHERE id = ?',
			$conversationId,
		)->fetchField();
	}


	/**
	 * The operator handles the conversation; automatic and AI replies stop until release().
	 */
	public function takeover(int $conversationId, int $operatorId): void
	{
		$this->database->transaction(function () use ($conversationId, $operatorId): void {
			$this->database->query(
				'UPDATE social_conversations SET human_takeover_at = NOW(3), assignee_id = ? WHERE id = ?',
				$operatorId,
				$conversationId,
			);
			$this->audit->write($operatorId, AuditAction::ConversationTakeover, self::TargetType, $conversationId);
		});
	}


	public function release(int $conversationId, int $operatorId): void
	{
		$this->database->transaction(function () use ($conversationId, $operatorId): void {
			$this->database->query(
				'UPDATE social_conversations SET human_takeover_at = NULL, assignee_id = NULL WHERE id = ?',
				$conversationId,
			);
			$this->audit->write($operatorId, AuditAction::ConversationRelease, self::TargetType, $conversationId);
		});
	}


	/**
	 * A message event without a customer cannot be assigned to a conversation and is skipped.
	 */
	public function apply(int $accountId, NormalizedEvent $event): void
	{
		$externalId = $event->externalId ?? throw new \LogicException('A message event has no external ID.');
		if ($event->customerId === null) {
			return;
		}

		$this->database->query(
			'INSERT INTO social_conversations ? ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
			[
				'account_id' => $accountId,
				'customer_external_id' => $event->customerId,
			],
		);
		$conversationId = (int) $this->database->getInsertId();

		$text = $event->text === null ? null : $this->encryptor->encrypt($event->text, self::TextContext);
		// Nette formats DateTime parameters without milliseconds.
		$occurredAt = $event->occurredAt?->format('Y-m-d H:i:s.v');
		match ($event->type) {
			EventType::MessageReceived, EventType::MessageEcho => $this->database->query(
				// A repeated delivery changes nothing; an unsent message never gets its text back.
				'INSERT INTO social_messages ? ON DUPLICATE KEY UPDATE id = id',
				[
					'conversation_id' => $conversationId,
					'external_id' => $externalId,
					'direction' => ($event->type === EventType::MessageEcho ? Direction::Outgoing : Direction::Incoming)->value,
					'text_encrypted' => $text,
					'sent_at' => $occurredAt,
				],
			),
			EventType::MessageDeleted => $this->database->query(
				'INSERT INTO social_messages ? ON DUPLICATE KEY UPDATE text_encrypted = NULL, deleted_at = VALUES(deleted_at)',
				[
					'conversation_id' => $conversationId,
					'external_id' => $externalId,
					'direction' => Direction::Incoming->value,
					'deleted_at' => $occurredAt ?? new SqlLiteral('NOW(3)'),
				],
			),
			default => throw new \LogicException("{$event->type->value} is not a message event."),
		};

		if ($event->type === EventType::MessageReceived && $occurredAt !== null) {
			$this->database->query(
				'UPDATE social_conversations
				SET window_expires_at = GREATEST(COALESCE(last_customer_message_at, ?), ?) + INTERVAL ? HOUR,
					last_customer_message_at = GREATEST(COALESCE(last_customer_message_at, ?), ?)
				WHERE id = ?',
				$occurredAt,
				$occurredAt,
				self::WindowHours,
				$occurredAt,
				$occurredAt,
				$conversationId,
			);
		}
	}


	/**
	 * Texts of other incoming messages of the customer's conversation sent within the last hours.
	 *
	 * @return list<string>
	 */
	public function recentTexts(int $accountId, string $customerId, string $exceptId, int $hours): array
	{
		$rows = $this->database->query(
			'SELECT m.text_encrypted
			FROM social_messages m
			JOIN social_conversations c ON c.id = m.conversation_id
			WHERE c.account_id = ? AND c.customer_external_id = ? AND m.external_id <> ?
				AND m.direction = ? AND m.text_encrypted IS NOT NULL
				AND COALESCE(m.sent_at, m.created_at) >= NOW(3) - INTERVAL ? HOUR
			ORDER BY m.id DESC
			LIMIT ?',
			$accountId,
			$customerId,
			$exceptId,
			Direction::Incoming->value,
			$hours,
			self::RecentLimit,
		)->fetchPairs(null, 'text_encrypted');

		return array_values(array_map(fn(string $text): string => $this->encryptor->decrypt($text, self::TextContext), $rows));
	}
}
