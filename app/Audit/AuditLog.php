<?php

declare(strict_types=1);

namespace App\Audit;

use Nette\Database\Explorer;
use Nette\Database\Row;
use Nette\Utils\Strings;


/**
 * admin_audit: who (operator, NULL = console) did what to which target.
 */
final class AuditLog
{
	public function __construct(
		private readonly Explorer $database,
	) {
	}


	public function count(?AuditAction $action): int
	{
		return (int) $this->database->query(
			'SELECT COUNT(*) FROM admin_audit WHERE ?and',
			$action === null ? [] : ['action' => $action->value],
		)->fetchField();
	}


	/**
	 * Newest first, with the operator's name (NULL = console).
	 *
	 * @return list<Row>
	 */
	public function page(?AuditAction $action, int $limit, int $offset): array
	{
		return $this->database->query(
			'SELECT a.id, a.created_at, a.action, a.target_type, a.target_id, a.details, o.name AS operator_name
			FROM admin_audit a
			LEFT JOIN operators o ON o.id = a.operator_id
			WHERE ?and
			ORDER BY a.id DESC
			LIMIT ? OFFSET ?',
			$action === null ? [] : ['a.action' => $action->value],
			$limit,
			$offset,
		)->fetchAll();
	}


	/**
	 * @param string|null $details changed values; never passwords or customer content
	 */
	public function write(
		?int $operatorId,
		AuditAction $action,
		string $targetType,
		string|int|null $targetId,
		?string $details = null,
	): void {
		$this->database->query('INSERT INTO admin_audit ?', [
			'operator_id' => $operatorId,
			'action' => $action->value,
			'target_type' => $targetType,
			'target_id' => $targetId === null ? null : (string) $targetId,
			'details' => $details === null ? null : Strings::truncate($details, 1000),
		]);
	}
}
