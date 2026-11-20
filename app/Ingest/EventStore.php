<?php

declare(strict_types=1);

namespace App\Ingest;

use App\Account\AccountStatus;
use App\Core\Encryptor;
use App\Queue\JobQueue;
use App\Queue\JobType;
use Nette\Database\Explorer;
use Nette\Database\SqlLiteral;


/**
 * Persists webhook events in one transaction with deduplication by event_key and queues the
 * new ones for the worker. Events of an account that is not connected or not active are stored
 * as ignored and never processed.
 */
final class EventStore
{
	public const PayloadContext = 'social_events.payload';


	public function __construct(
		private readonly Explorer $database,
		private readonly Encryptor $encryptor,
		private readonly JobQueue $queue,
	) {
	}


	public function save(WebhookBatch $batch): void
	{
		$this->database->transaction(function () use ($batch): void {
			foreach ($batch->events as $event) {
				$account = $this->database->query(
					'SELECT id, status FROM social_accounts WHERE platform = ? AND external_id = ?',
					$event->platform->value,
					$event->accountExternalId,
				)->fetch();
				$active = $account !== null && $account->status === AccountStatus::Active->value;

				// A duplicate (same platform, account and key) changes nothing: 0 affected rows.
				$inserted = $this->database->query(
					'INSERT INTO social_events ? ON DUPLICATE KEY UPDATE id = id',
					[
						'platform' => $event->platform->value,
						'account_external_id' => $event->accountExternalId,
						'account_id' => $account?->id,
						'event_key' => $event->eventKey,
						'topic' => $event->topic,
						'payload_encrypted' => $this->encryptor->encrypt($event->payload, self::PayloadContext),
						'status' => ($active ? EventStatus::Received : EventStatus::Ignored)->value,
						'received_at' => new SqlLiteral('NOW(3)'),
					],
				)->getRowCount() === 1;

				if ($inserted && $active) {
					$this->queue->push(JobType::ProcessEvent, (int) $this->database->getInsertId());
				}
			}
		});
	}
}
