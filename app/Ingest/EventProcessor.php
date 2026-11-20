<?php

declare(strict_types=1);

namespace App\Ingest;

use App\Core\Encryptor;
use Nette\Database\Explorer;


/**
 * Worker step for one stored event (job event.process).
 *
 * The EventNormalizer needs the Meta login flow, which is not decided yet (specification §3.1,
 * Milestone 0). Until then every event goes to `review`: an unclear state never leads to an
 * automatic action. The payload is still decrypted, so a broken key or modified data fails here.
 */
final class EventProcessor
{
	public function __construct(
		private readonly Explorer $database,
		private readonly Encryptor $encryptor,
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
		$this->encryptor->decrypt($event->payload_encrypted, EventStore::PayloadContext);

		$this->database->query(
			'UPDATE social_events SET status = ? WHERE id = ? AND status = ?',
			EventStatus::Review->value,
			$eventId,
			EventStatus::Received->value,
		);
	}
}
