<?php

declare(strict_types=1);

namespace App\Ingest;

use App\Comment\Comments;
use App\Conversation\Conversations;
use App\Core\Encryptor;
use App\Meta\EventNormalizer;
use App\Meta\EventType;
use App\Meta\NormalizedEvent;
use DateTimeImmutable;
use DateTimeInterface;
use App\Queue\JobQueue;
use App\Queue\JobType;
use Nette\Database\Explorer;


/**
 * Worker step for one stored event (job event.process): normalizes it, stores its metadata and
 * projects comments and messages into their current state (Comments, Conversations).
 * Events that are not relevant for moderation (reactions, read receipts, own posts) and the
 * account's own comments and message echoes end as ignored, so they never trigger anything.
 * Everything else goes to `review`; a comment or message with text is queued for the AI decision
 * (job event.decide), so an AI outage never blocks the ingest. An unsupported structure always
 * needs a human.
 */
final class EventProcessor
{
	public function __construct(
		private readonly Explorer $database,
		private readonly Encryptor $encryptor,
		private readonly EventNormalizer $normalizer,
		private readonly JobQueue $queue,
		private readonly Comments $comments,
		private readonly Conversations $conversations,
	) {
	}


	public function process(int $eventId): void
	{
		$event = $this->database->query(
			'SELECT status, account_id, payload_encrypted, received_at FROM social_events WHERE id = ?',
			$eventId,
		)->fetch();
		if ($event === null) {
			throw new \RuntimeException("Event $eventId does not exist.");
		}

		// A retried job whose previous attempt already finished the event.
		if ($event->status !== EventStatus::Received->value) {
			return;
		}

		$this->database->query('UPDATE social_events SET attempts = attempts + 1 WHERE id = ?', $eventId);
		$normalized = $this->normalizer->normalize(
			$this->encryptor->decrypt($event->payload_encrypted, EventStore::PayloadContext),
		);
		$status = self::isIgnored($normalized) ? EventStatus::Ignored : EventStatus::Review;

		$this->database->transaction(function () use ($eventId, $normalized, $status, $event): void {
			$updated = $this->database->query(
				'UPDATE social_events SET ? WHERE id = ? AND status = ?',
				$normalized->columns() + [
					'status' => $status->value,
				],
				$eventId,
				EventStatus::Received->value,
			)->getRowCount() === 1;

			if (!$updated) {
				return;
			}

			$this->project((int) $event->account_id, $normalized, $event->received_at);
			if ($status === EventStatus::Review && $normalized->type->contentKind() !== null && $normalized->text !== null) {
				$this->queue->push(JobType::DecideEvent, $eventId);
			}
		});
	}


	/**
	 * Projects a comment or message event into its current state; other events change nothing.
	 * Idempotent; the caller runs it in a transaction.
	 */
	public function project(int $accountId, NormalizedEvent $event, DateTimeInterface $receivedAt): void
	{
		match ($event->type) {
			EventType::CommentCreated,
			EventType::CommentEdited,
			EventType::CommentRemoved,
			EventType::CommentHidden,
			EventType::CommentUnhidden => $this->comments->apply(
				$accountId,
				$event,
				$event->occurredAt ?? DateTimeImmutable::createFromInterface($receivedAt),
			),
			EventType::MessageReceived,
			EventType::MessageEcho,
			EventType::MessageDeleted => $this->conversations->apply($accountId, $event),
			default => null,
		};
	}


	private static function isIgnored(NormalizedEvent $event): bool
	{
		return $event->type === EventType::Other
			|| $event->type === EventType::MessageEcho
			|| $event->own;
	}
}
