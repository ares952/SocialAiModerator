<?php

declare(strict_types=1);

namespace App\Moderation;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use Nette\Database\Explorer;
use Nette\Database\Row;
use Nette\Utils\Json;


/**
 * Versioned global moderation policy. Saving never edits a version: it adds a new one and
 * activates it, so every decision can be traced to the exact rules (policy_version).
 * Per-account policies (moderation_policies.account_id) follow with account management.
 */
final class Policies
{
	public function __construct(
		private readonly Explorer $database,
		private readonly AuditLog $audit,
		private readonly Mode $defaultMode,
	) {
	}


	public function active(): Policy
	{
		$row = $this->database->query(
			'SELECT version, mode, settings_json FROM moderation_policies WHERE account_id IS NULL AND active = 1',
		)->fetch();

		return $row === null ? $this->default() : self::policy($row);
	}


	/**
	 * A stored version, or the default for version 0.
	 */
	public function version(int $version): ?Policy
	{
		if ($version === 0) {
			return $this->default();
		}

		$row = $this->database->query(
			'SELECT version, mode, settings_json FROM moderation_policies WHERE version = ?',
			$version,
		)->fetch();

		return $row === null ? null : self::policy($row);
	}


	/**
	 * @return list<Row> newest first
	 */
	public function history(): array
	{
		return $this->database->query(
			'SELECT p.version, p.mode, p.active, p.created_at, o.name AS created_by_name
			FROM moderation_policies p
			LEFT JOIN operators o ON o.id = p.created_by
			WHERE p.account_id IS NULL
			ORDER BY p.version DESC',
		)->fetchAll();
	}


	public function save(Mode $mode, PolicySettings $settings, int $operatorId): Policy
	{
		return $this->database->transaction(function () use ($mode, $settings, $operatorId): Policy {
			$version = 1 + (int) $this->database->query(
				'SELECT MAX(version) FROM moderation_policies FOR UPDATE',
			)->fetchField();

			$this->database->query('UPDATE moderation_policies SET active = 0 WHERE account_id IS NULL AND active = 1');
			$this->database->query('INSERT INTO moderation_policies ?', [
				'version' => $version,
				'mode' => $mode->value,
				'settings_json' => Json::encode($settings->toArray()),
				'active' => 1,
				'created_by' => $operatorId,
			]);
			$this->audit->write($operatorId, AuditAction::PolicyUpdate, 'policy', $version, "mode=$mode->value");

			return new Policy($version, $mode, $settings);
		});
	}


	private function default(): Policy
	{
		return new Policy(0, $this->defaultMode, new PolicySettings);
	}


	private static function policy(Row $row): Policy
	{
		$settings = Json::decode($row->settings_json, forceArrays: true);

		return new Policy(
			(int) $row->version,
			Mode::from($row->mode),
			PolicySettings::fromArray(is_array($settings) ? $settings : []),
		);
	}
}
