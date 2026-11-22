<?php

declare(strict_types=1);

namespace App\Comment;

use App\Core\Encryptor;
use App\Meta\EventType;
use App\Meta\NormalizedEvent;
use DateTimeImmutable;
use DateTimeInterface;
use Nette\Database\Explorer;
use Nette\Database\Row;
use Nette\Database\SqlLiteral;


/**
 * Current state of posts and comments, projected from comment events (specification §3.3).
 * Events may come repeatedly and out of order, so every change carries its time and an older
 * change never overwrites a newer one; a removed comment stays removed and loses its text.
 * The projection is idempotent. Runs inside the transaction of the event processing.
 */
final class Comments
{
	public const TextContext = 'social_comments.text';

	/** Upper bound of texts read for the repeated-text rule; protects the worker, not a policy. */
	private const RecentLimit = 50;


	public function __construct(
		private readonly Explorer $database,
		private readonly Encryptor $encryptor,
	) {
	}


	/**
	 * @param DateTimeImmutable $time time of the change according to Meta, or when it was received
	 */
	public function apply(int $accountId, NormalizedEvent $event, DateTimeImmutable $time): void
	{
		$externalId = $event->externalId ?? throw new \LogicException('A comment event has no external ID.');
		$postId = $event->postId === null ? null : $this->post($accountId, $event->postId, $event->permalink);

		// Lock the row first, so two events of one new comment never race.
		$this->database->query('INSERT INTO social_comments ? ON DUPLICATE KEY UPDATE id = id', [
			'account_id' => $accountId,
			'external_id' => $externalId,
			'visibility' => Visibility::Visible->value,
		]);
		$row = $this->database->query(
			'SELECT id, post_id, parent_external_id, author_external_id, own, visibility, text_changed_at, visibility_changed_at
			FROM social_comments
			WHERE account_id = ? AND external_id = ?
			FOR UPDATE',
			$accountId,
			$externalId,
		)->fetch() ?? throw new \LogicException('The comment row is missing.');

		$this->database->query('UPDATE social_comments SET ? WHERE id = ?', [
			'post_id' => $row->post_id ?? $postId,
			'parent_external_id' => $row->parent_external_id ?? $event->parentId,
			'author_external_id' => $row->author_external_id ?? $event->authorId,
			'own' => $row->own || $event->own,
			'updated_at' => new SqlLiteral('NOW(3)'),
		] + $this->change($row, $event, $time), $row->id);
	}


	/**
	 * Comment of the account by its external ID; null when no event projected it yet.
	 */
	public function find(int $accountId, string $externalId): ?Row
	{
		return $this->database->query(
			'SELECT id, external_id, visibility, own FROM social_comments WHERE account_id = ? AND external_id = ?',
			$accountId,
			$externalId,
		)->fetch();
	}


	/**
	 * Current text of a comment; null when unknown or removed. Customer content.
	 */
	public function text(int $commentId): ?string
	{
		$text = $this->database->query('SELECT text_encrypted FROM social_comments WHERE id = ?', $commentId)->fetchField();

		return is_string($text) ? $this->encryptor->decrypt($text, self::TextContext) : null;
	}


	/**
	 * Visibility after Meta confirmed the application's own hide or unhide; a removed comment stays
	 * removed and a later change from Meta still wins by its time.
	 */
	public function setVisibility(int $commentId, Visibility $visibility): void
	{
		$this->database->query(
			'UPDATE social_comments SET visibility = ?, visibility_changed_at = NOW(3), updated_at = NOW(3)
			WHERE id = ? AND visibility <> ? AND (visibility_changed_at IS NULL OR visibility_changed_at <= NOW(3))',
			$visibility->value,
			$commentId,
			Visibility::Removed->value,
		);
	}


	/**
	 * Texts of other comments by the same author on the account changed within the last hours.
	 *
	 * @return list<string>
	 */
	public function recentTexts(int $accountId, string $authorId, string $exceptId, int $hours): array
	{
		$rows = $this->database->query(
			'SELECT text_encrypted
			FROM social_comments
			WHERE account_id = ? AND author_external_id = ? AND external_id <> ?
				AND text_encrypted IS NOT NULL AND text_changed_at >= NOW(3) - INTERVAL ? HOUR
			ORDER BY text_changed_at DESC
			LIMIT ?',
			$accountId,
			$authorId,
			$exceptId,
			$hours,
			self::RecentLimit,
		)->fetchPairs(null, 'text_encrypted');

		return array_values(array_map(fn(string $text): string => $this->encryptor->decrypt($text, self::TextContext), $rows));
	}


	/**
	 * @return array<string, mixed>
	 */
	private function change(Row $row, NormalizedEvent $event, DateTimeImmutable $time): array
	{
		if ($row->visibility === Visibility::Removed->value) {
			return [];
		}

		$newer = static fn(?DateTimeInterface $changedAt): bool => $changedAt === null || $time >= $changedAt;
		// Nette formats DateTime parameters without milliseconds.
		$at = $time->format('Y-m-d H:i:s.v');

		return match ($event->type) {
			EventType::CommentCreated, EventType::CommentEdited => $event->text !== null && $newer($row->text_changed_at)
				? [
					'text_encrypted' => $this->encryptor->encrypt($event->text, self::TextContext),
					'text_changed_at' => $at,
				]
				: [],
			EventType::CommentHidden, EventType::CommentUnhidden => $newer($row->visibility_changed_at)
				? [
					'visibility' => ($event->type === EventType::CommentHidden ? Visibility::Hidden : Visibility::Visible)->value,
					'visibility_changed_at' => $at,
				]
				: [],
			EventType::CommentRemoved => [
				'visibility' => Visibility::Removed->value,
				'visibility_changed_at' => $at,
				'text_encrypted' => null,
				'text_changed_at' => $at,
			],
			default => throw new \LogicException("{$event->type->value} is not a comment event."),
		};
	}


	private function post(int $accountId, string $externalId, ?string $permalink): int
	{
		$this->database->query(
			'INSERT INTO social_posts ? ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), permalink = COALESCE(VALUES(permalink), permalink)',
			[
				'account_id' => $accountId,
				'external_id' => $externalId,
				'permalink' => $permalink,
			],
		);

		return (int) $this->database->getInsertId();
	}
}
