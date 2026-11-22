<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Core\Encryptor;
use App\Outbox\ActionStatus;
use Nette\Database\Explorer;
use Nette\Database\Row;


/**
 * Unified inbox of Messenger and Instagram Direct conversations for the administration
 * (specification §4.3, FR-M01): metadata, the reply window, takeover and pending replies. Message
 * texts are decrypted only by messages(), for those who may read customer content.
 */
final class Inbox
{
	private const Columns = 'c.id, c.customer_external_id, c.last_customer_message_at, c.window_expires_at,
		c.window_expires_at > NOW(3) AS window_open, c.human_takeover_at, o.name AS assignee_name,
		s.platform, s.name AS account_name,
		(SELECT MAX(COALESCE(m.sent_at, m.created_at)) FROM social_messages m WHERE m.conversation_id = c.id) AS last_message_at,
		(SELECT COUNT(*) FROM moderation_actions a JOIN social_messages m ON m.id = a.message_id
			WHERE m.conversation_id = c.id AND a.status = ?) AS pending_replies';

	private const From = 'FROM social_conversations c
		JOIN social_accounts s ON s.id = c.account_id
		LEFT JOIN operators o ON o.id = c.assignee_id';


	public function __construct(
		private readonly Explorer $database,
		private readonly Encryptor $encryptor,
	) {
	}


	public function count(?InboxFilter $filter): int
	{
		[$condition, $params] = self::condition($filter);

		return (int) $this->database->query('SELECT COUNT(*) ' . self::From . ' WHERE ' . $condition, ...$params)->fetchField();
	}


	/**
	 * Latest activity first.
	 *
	 * @return list<Row>
	 */
	public function page(?InboxFilter $filter, int $limit, int $offset): array
	{
		[$condition, $params] = self::condition($filter);

		return $this->database->query(
			'SELECT ' . self::Columns . ' ' . self::From . ' WHERE ' . $condition . '
			ORDER BY last_message_at DESC, c.id DESC
			LIMIT ? OFFSET ?',
			ActionStatus::Pending->value,
			...[
				...$params,
				$limit,
				$offset,
			],
		)->fetchAll();
	}


	public function find(int $id): ?Row
	{
		return $this->database->query(
			'SELECT ' . self::Columns . ', c.account_id ' . self::From . ' WHERE c.id = ?',
			ActionStatus::Pending->value,
			$id,
		)->fetch();
	}


	/**
	 * Messages oldest first, with their decrypted text; customer content.
	 *
	 * @return list<array{id: int, direction: string, text: ?string, sent_at: ?\DateTimeInterface, created_at: \DateTimeInterface, deleted: bool}>
	 */
	public function messages(int $conversationId): array
	{
		$rows = $this->database->query(
			'SELECT id, direction, text_encrypted, sent_at, created_at, deleted_at
			FROM social_messages WHERE conversation_id = ? ORDER BY COALESCE(sent_at, created_at), id',
			$conversationId,
		)->fetchAll();

		return array_map(fn(Row $row) => [
			'id' => (int) $row->id,
			'direction' => (string) $row->direction,
			'text' => $row->text_encrypted === null ? null : $this->encryptor->decrypt($row->text_encrypted, Conversations::TextContext),
			'sent_at' => $row->sent_at,
			'created_at' => $row->created_at,
			'deleted' => $row->deleted_at !== null,
		], $rows);
	}


	/**
	 * @return array{literal-string, list<string>}
	 */
	private static function condition(?InboxFilter $filter): array
	{
		return match ($filter) {
			null => [
				'1 = 1',
				[],
			],
			InboxFilter::Open => [
				'c.window_expires_at > NOW(3)',
				[],
			],
			InboxFilter::Takeover => [
				'c.human_takeover_at IS NOT NULL',
				[],
			],
			InboxFilter::Pending => [
				'EXISTS (SELECT 1 FROM moderation_actions a JOIN social_messages m ON m.id = a.message_id
					WHERE m.conversation_id = c.id AND a.status = ?)',
				[
					ActionStatus::Pending->value,
				],
			],
		};
	}
}
