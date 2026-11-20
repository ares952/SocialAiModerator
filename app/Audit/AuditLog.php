<?php

declare(strict_types=1);

namespace App\Audit;

use Nette\Database\Explorer;
use Nette\Utils\Strings;


/**
 * Writes admin_audit: who (operator, NULL = console) did what to which target.
 */
final class AuditLog
{
	public function __construct(
		private readonly Explorer $database,
	) {
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
