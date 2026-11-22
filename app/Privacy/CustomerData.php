<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Comment\Comments;
use App\Conversation\Conversations;
use App\Core\Encryptor;
use App\Queue\JobType;
use Nette\Database\Explorer;


/**
 * Personal data of one customer for data subject requests (specification §8.3): what the
 * application stores, an export of it, and its deletion. A customer is identified by the ID Meta
 * uses for them, with the platform prefix as the administration shows it (fb:…, ig:…): the author
 * of comments, or the PSID/IGSID of a conversation (Facebook uses different IDs for comments and
 * Messenger, so each is looked up on its own). Events of the
 * customer's comments and messages go with them, including AI decisions, interventions and
 * queue jobs. The audit stores only a hash of the ID. Data at Meta is not affected.
 */
final class CustomerData
{
	private const IdPattern = '~^(fb|ig):[0-9A-Za-z_.-]{1,100}$~D';


	public function __construct(
		private readonly Explorer $database,
		private readonly Encryptor $encryptor,
		private readonly AuditLog $audit,
	) {
	}


	public static function isValidId(string $externalId): bool
	{
		return preg_match(self::IdPattern, $externalId) === 1;
	}


	/**
	 * @return array{comments: int, conversations: int, messages: int, events: int}
	 */
	public function summary(string $externalId): array
	{
		$ids = $this->ids($externalId);

		return [
			'comments' => count($ids['comments']),
			'conversations' => count($ids['conversations']),
			'messages' => (int) ($ids['conversations'] === [] ? 0 : $this->database->query(
				'SELECT COUNT(*) FROM social_messages WHERE conversation_id IN (?)',
				$ids['conversations'],
			)->fetchField()),
			'events' => count($ids['events']),
		];
	}


	/**
	 * Everything stored about the customer, readable (texts decrypted); audited.
	 *
	 * @return array<string, mixed>
	 */
	public function export(string $externalId, int $operatorId): array
	{
		$ids = $this->ids($externalId);
		$decrypt = fn(mixed $text, string $context): ?string => is_string($text) ? $this->encryptor->decrypt($text, $context) : null;

		$comments = [];
		foreach ($ids['comments'] === [] ? [] : $this->database->query(
			'SELECT c.external_id, a.name AS account, c.text_encrypted, c.visibility, c.created_at
			FROM social_comments c JOIN social_accounts a ON a.id = c.account_id
			WHERE c.id IN (?) ORDER BY c.id',
			$ids['comments'],
		) as $row) {
			$comments[] = [
				'id' => $row->external_id,
				'account' => $row->account,
				'created_at' => $row->created_at->format(DATE_ATOM),
				'visibility' => $row->visibility,
				'text' => $decrypt($row->text_encrypted, Comments::TextContext),
			];
		}

		$messages = [];
		foreach ($ids['conversations'] === [] ? [] : $this->database->query(
			'SELECT m.external_id, a.name AS account, m.direction, m.text_encrypted, m.sent_at, m.created_at
			FROM social_messages m
			JOIN social_conversations v ON v.id = m.conversation_id
			JOIN social_accounts a ON a.id = v.account_id
			WHERE v.id IN (?) ORDER BY m.id',
			$ids['conversations'],
		) as $row) {
			$messages[] = [
				'id' => $row->external_id,
				'account' => $row->account,
				'direction' => $row->direction,
				'sent_at' => ($row->sent_at ?? $row->created_at)->format(DATE_ATOM),
				'text' => $decrypt($row->text_encrypted, Conversations::TextContext),
			];
		}

		$decisions = [];
		foreach ($ids['events'] === [] ? [] : $this->database->query(
			'SELECT e.external_id, d.category, d.action, d.created_at
			FROM moderation_decisions d JOIN social_events e ON e.id = d.event_id
			WHERE e.id IN (?) ORDER BY d.id',
			$ids['events'],
		) as $row) {
			$decisions[] = [
				'content_id' => $row->external_id,
				'created_at' => $row->created_at->format(DATE_ATOM),
				'category' => $row->category,
				'action' => $row->action,
			];
		}

		$this->audit->write($operatorId, AuditAction::PrivacyExport, 'customer', null, self::fingerprint($externalId));

		return [
			'customer' => $externalId,
			'exported_at' => gmdate(DATE_ATOM),
			'comments' => $comments,
			'messages' => $messages,
			'ai_decisions' => $decisions,
		];
	}


	/**
	 * Deletes everything stored about the customer; audited with the counts.
	 *
	 * @return array{comments: int, conversations: int, messages: int, events: int}
	 */
	public function delete(string $externalId, int $operatorId): array
	{
		$summary = $this->summary($externalId);

		$this->database->transaction(function () use ($externalId, $summary, $operatorId): void {
			$ids = $this->ids($externalId);
			$actions = $this->database->query(
				'SELECT a.id FROM moderation_actions a
				LEFT JOIN social_messages m ON m.id = a.message_id
				WHERE a.comment_id IN (?) OR m.conversation_id IN (?)',
				$ids['comments'] ?: [0],
				$ids['conversations'] ?: [0],
			)->fetchPairs(null, 'id');
			if ($actions !== []) {
				$this->database->query(
					'DELETE FROM queue_jobs WHERE type IN (?) AND subject_id IN (?)',
					[
						JobType::DraftReply->value,
						JobType::SendAction->value,
					],
					$actions,
				);
			}
			if ($ids['events'] !== []) {
				$this->database->query(
					'DELETE FROM queue_jobs WHERE type IN (?) AND subject_id IN (?)',
					[
						JobType::ProcessEvent->value,
						JobType::DecideEvent->value,
					],
					$ids['events'],
				);
				$this->database->query('DELETE FROM social_events WHERE id IN (?)', $ids['events']);
			}
			if ($ids['comments'] !== []) {
				$this->database->query('DELETE FROM social_comments WHERE id IN (?)', $ids['comments']);
			}
			if ($ids['conversations'] !== []) {
				$this->database->query('DELETE FROM social_conversations WHERE id IN (?)', $ids['conversations']);
			}

			$this->audit->write($operatorId, AuditAction::PrivacyDelete, 'customer', null, sprintf(
				'%s; comments=%d, conversations=%d, messages=%d, events=%d',
				self::fingerprint($externalId),
				$summary['comments'],
				$summary['conversations'],
				$summary['messages'],
				$summary['events'],
			));
		});

		return $summary;
	}


	/**
	 * @return array{comments: list<int>, conversations: list<int>, events: list<int>}
	 */
	private function ids(string $externalId): array
	{
		if (!self::isValidId($externalId)) {
			throw new \InvalidArgumentException('Invalid customer ID.');
		}

		$comments = array_map(intval(...), $this->database->query(
			'SELECT id FROM social_comments WHERE author_external_id = ?',
			$externalId,
		)->fetchPairs(null, 'id'));
		$conversations = array_map(intval(...), $this->database->query(
			'SELECT id FROM social_conversations WHERE customer_external_id = ?',
			$externalId,
		)->fetchPairs(null, 'id'));
		$events = array_map(intval(...), $this->database->query(
			'SELECT e.id FROM social_events e
			WHERE e.external_id IN (SELECT c.external_id FROM social_comments c WHERE c.author_external_id = ?)
				OR e.external_id IN (
					SELECT m.external_id FROM social_messages m
					JOIN social_conversations v ON v.id = m.conversation_id
					WHERE v.customer_external_id = ?
				)',
			$externalId,
			$externalId,
		)->fetchPairs(null, 'id'));

		return [
			'comments' => array_values($comments),
			'conversations' => array_values($conversations),
			'events' => array_values($events),
		];
	}


	/**
	 * Identifies the request in the audit without storing the customer's ID.
	 */
	private static function fingerprint(string $externalId): string
	{
		return 'sha256:' . substr(hash('sha256', $externalId), 0, 16);
	}
}
