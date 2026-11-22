<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use Nette\Database\Explorer;


/**
 * Editable thresholds of the alarms (admin only, audited); a missing row or NULL turns the alarm off.
 */
final class AlarmThresholds
{
	public function __construct(
		private readonly Explorer $database,
		private readonly AuditLog $audit,
	) {
	}


	/**
	 * @return array<string, int|null> AlarmKind value => threshold, null = off
	 */
	public function all(): array
	{
		$stored = $this->database->query('SELECT kind, value FROM alarm_thresholds')->fetchPairs('kind', 'value');
		$thresholds = [];
		foreach (AlarmKind::cases() as $kind) {
			$value = $stored[$kind->value] ?? null;
			$thresholds[$kind->value] = $value === null ? null : (int) $value;
		}

		return $thresholds;
	}


	/**
	 * @param array<string, int|null> $thresholds AlarmKind value => threshold, null = off
	 */
	public function save(array $thresholds, int $operatorId): void
	{
		$this->database->transaction(function () use ($thresholds, $operatorId): void {
			$details = [];
			foreach (AlarmKind::cases() as $kind) {
				$value = $thresholds[$kind->value] ?? null;
				if ($value !== null && ($value < 1 || $value > $kind->max())) {
					throw new \InvalidArgumentException("Threshold of $kind->value must be 1 to {$kind->max()}.");
				}

				$this->database->query(
					'INSERT INTO alarm_thresholds ? ON DUPLICATE KEY UPDATE ?',
					[
						'kind' => $kind->value,
						'value' => $value,
						'updated_by' => $operatorId,
					],
					[
						'value' => $value,
						'updated_by' => $operatorId,
					],
				);
				$details[] = "$kind->value=" . ($value ?? 'off');
			}
			$this->audit->write($operatorId, AuditAction::AlarmsUpdate, 'alarms', null, implode(', ', $details));
		});
	}
}
