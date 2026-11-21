<?php

declare(strict_types=1);

namespace App\Ingest;

use App\Core\Encryptor;
use App\Meta\EventNormalizer;
use App\Meta\EventType;
use App\Queue\JobQueue;
use App\Queue\JobType;
use Nette\Database\Explorer;


/**
 * Worker step for one stored event (job event.process): normalizes it and stores its metadata.
 * Events that are not relevant for moderation (reactions, read receipts, own posts) end as
 * ignored. Everything else goes to `review`; a comment or message with text is queued for the
 * AI decision (job event.decide), so an AI outage never blocks the ingest. An unsupported
 * structure always needs a human.
 */
final class EventProcessor
{
	public function __construct(
		private readonly Explorer $database,
		private readonly Encryptor $encryptor,
		private readonly EventNormalizer $normalizer,
		private readonly JobQueue $queue,
	) {
	}


	public function process(int $eventId): void
	{
		$event = $this->database->query(
			'SELECT status, payload_encrypted FROM social_events WHERE id = ?',
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
		$status = $normalized->type === EventType::Other ? EventStatus::Ignored : EventStatus::Review;

		$this->database->transaction(function () use ($eventId, $normalized, $status): void {
			$updated = $this->database->query(
				'UPDATE social_events SET ? WHERE id = ? AND status = ?',
				$normalized->columns() + [
					'status' => $status->value,
				],
				$eventId,
				EventStatus::Received->value,
			)->getRowCount() === 1;

			if ($updated && $normalized->type->contentKind() !== null && $normalized->text !== null) {
				$this->queue->push(JobType::DecideEvent, $eventId);
			}
		});
	}
}
