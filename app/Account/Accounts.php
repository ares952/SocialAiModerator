<?php

declare(strict_types=1);

namespace App\Account;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Meta\Platform;
use Nette\Database\Explorer;
use Nette\Database\Row;
use Nette\Database\UniqueConstraintViolationException;


/**
 * Connected Facebook Pages and Instagram professional accounts. Webhook events are processed only
 * for active accounts. Accounts are registered by hand; their access tokens are in Credentials.
 */
final class Accounts
{
	public function __construct(
		private readonly Explorer $database,
		private readonly AuditLog $audit,
	) {
	}


	/**
	 * @return list<Row> with the number of events in the last 24 hours, an open pause and the
	 *     token metadata (never the token)
	 */
	public function all(): array
	{
		return $this->database->query(
			'SELECT a.id, a.platform, a.external_id, a.name, a.status, a.created_at,
				(SELECT COUNT(*) FROM social_events e WHERE e.account_id = a.id AND e.received_at > NOW() - INTERVAL 1 DAY) AS events_day,
				EXISTS (SELECT 1 FROM automation_pauses p WHERE p.account_id = a.id AND p.resumed_at IS NULL) AS paused,
				c.token_type, c.status AS token_status, c.expires_at AS token_expires_at,
				c.data_access_expires_at AS token_data_access_expires_at, c.checked_at AS token_checked_at,
				c.refreshed_at AS token_refreshed_at
			FROM social_accounts a
			LEFT JOIN account_credentials c ON c.social_account_id = a.id
			ORDER BY a.name',
		)->fetchAll();
	}


	public function find(int $id): ?Row
	{
		return $this->database->query(
			'SELECT id, platform, external_id, name, status FROM social_accounts WHERE id = ?',
			$id,
		)->fetch();
	}


	/**
	 * @param string $externalId numeric Page or IG account ID as Meta sends it in entry.id
	 * @throws InvalidAccount
	 */
	public function create(Platform $platform, string $externalId, string $name, AccountStatus $status, int $operatorId): int
	{
		$externalId = trim($externalId);
		if (!preg_match('~^\d{1,64}$~', $externalId)) {
			throw new InvalidAccount('account.error.external-id');
		}
		if (trim($name) === '') {
			throw new InvalidAccount('form.error.required');
		}

		try {
			$this->database->query('INSERT INTO social_accounts ?', [
				'platform' => $platform->value,
				'external_id' => $platform->externalId($externalId),
				'name' => trim($name),
				'status' => $status->value,
			]);
		} catch (UniqueConstraintViolationException) {
			throw new InvalidAccount('account.error.duplicate');
		}

		$id = (int) $this->database->getInsertId();
		$this->audit->write($operatorId, AuditAction::AccountCreate, 'account', $id, "status=$status->value");

		return $id;
	}


	/**
	 * @throws InvalidAccount
	 */
	public function update(int $id, string $name, AccountStatus $status, int $operatorId): void
	{
		if (trim($name) === '') {
			throw new InvalidAccount('form.error.required');
		}

		$current = $this->find($id) ?? throw new InvalidAccount('account.error.not-found');
		$this->database->query(
			'UPDATE social_accounts SET name = ?, status = ? WHERE id = ?',
			trim($name),
			$status->value,
			$id,
		);
		$this->audit->write($operatorId, AuditAction::AccountUpdate, 'account', $id, "status=$current->status->$status->value");
	}
}
