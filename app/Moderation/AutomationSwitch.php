<?php

declare(strict_types=1);

namespace App\Moderation;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use Nette\Database\Explorer;
use Nette\Database\Row;


/**
 * Panic switch (specification §4.3): a pause blocks every automatic Meta write, globally or for
 * one account, while events keep being received and stored. Pauses are kept as history.
 */
final class AutomationSwitch
{
	public function __construct(
		private readonly Explorer $database,
		private readonly AuditLog $audit,
	) {
	}


	/**
	 * Whether automatic actions of the account (or all accounts for null) are paused.
	 */
	public function isPaused(?int $accountId): bool
	{
		return (bool) $this->database->query(
			'SELECT 1 FROM automation_pauses WHERE resumed_at IS NULL AND (account_id IS NULL OR account_id = ?) LIMIT 1',
			$accountId,
		)->fetchField();
	}


	/**
	 * The global pause in effect.
	 */
	public function globalPause(): ?Row
	{
		return $this->database->query(
			'SELECT p.reason, p.paused_at, o.name AS paused_by_name
			FROM automation_pauses p
			LEFT JOIN operators o ON o.id = p.paused_by
			WHERE p.resumed_at IS NULL AND p.account_id IS NULL',
		)->fetch();
	}


	public function pause(?int $accountId, ?string $reason, int $operatorId): void
	{
		$this->database->transaction(function () use ($accountId, $reason, $operatorId): void {
			$open = $this->database->query(
				'SELECT id FROM automation_pauses WHERE resumed_at IS NULL AND account_id <=> ? FOR UPDATE',
				$accountId,
			)->fetchField();
			if ($open !== null) {
				return;
			}

			$this->database->query('INSERT INTO automation_pauses ?', [
				'account_id' => $accountId,
				'reason' => $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, 255),
				'paused_by' => $operatorId,
			]);
			$this->audit->write($operatorId, AuditAction::AutomationPause, 'automation', $accountId ?? 'global');
		});
	}


	public function resume(?int $accountId, int $operatorId): void
	{
		$resumed = $this->database->query(
			'UPDATE automation_pauses SET resumed_at = NOW(), resumed_by = ? WHERE resumed_at IS NULL AND account_id <=> ?',
			$operatorId,
			$accountId,
		)->getRowCount();

		if ($resumed > 0) {
			$this->audit->write($operatorId, AuditAction::AutomationResume, 'automation', $accountId ?? 'global');
		}
	}
}
