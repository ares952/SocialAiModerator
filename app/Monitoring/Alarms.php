<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Account\AccountStatus;
use App\Account\TokenStatus;
use App\Moderation\Mode;
use App\Moderation\Policies;
use App\Outbox\ActionStatus;
use App\Outbox\ActionType;
use App\Queue\JobStatus;
use Nette\Database\Explorer;


/**
 * Current alarms (specification §8.4), computed on demand for the dashboard and for
 * `monitor:check`: token problems, no webhooks from an active account, a stuck queue or worker,
 * repeated failures and open circuit breakers, many hidden comments, actions with an unknown result
 * and approvals waiting too long (not in observe mode, where nothing can be approved), and a
 * missing or old scheduled database backup (bin/backup writes its status file; the application
 * cannot see the dumps). Thresholds come from AlarmThresholds; results never contain customer
 * content.
 */
final class Alarms
{
	public function __construct(
		private readonly string $heartbeatFile,
		private readonly string $backupStatusFile,
		private readonly Explorer $database,
		private readonly AlarmThresholds $thresholds,
		private readonly Policies $policies,
	) {
	}


	/**
	 * @return list<Alarm>
	 */
	public function current(): array
	{
		$limits = $this->thresholds->all();

		return [
			...$this->tokens($limits[AlarmKind::TokenExpiry->value]),
			...$this->silence($limits[AlarmKind::WebhookSilence->value]),
			...$this->queue($limits[AlarmKind::QueueLag->value]),
			...$this->failures($limits[AlarmKind::Failures->value]),
			...$this->actions($limits[AlarmKind::HiddenComments->value], $limits[AlarmKind::StaleApprovals->value]),
			...$this->backup($limits[AlarmKind::BackupAge->value]),
		];
	}


	/**
	 * @return list<Alarm>
	 */
	private function backup(?int $hours): array
	{
		if ($hours === null) {
			return [];
		}

		$created = is_file($this->backupStatusFile) ? filemtime($this->backupStatusFile) : false;
		if ($created === false) {
			return [
				new Alarm('backup_missing'),
			];
		}

		$age = intdiv(time() - $created, 3600);

		return $age >= $hours ? [
			new Alarm('backup_stale', [
				'hours' => $age,
			]),
		] : [];
	}


	/**
	 * @return list<Alarm>
	 */
	private function tokens(?int $days): array
	{
		$rows = $this->database->query(
			'SELECT a.name, c.id AS credential, c.status,
				c.expires_at <= NOW() AS expired, c.data_access_expires_at <= NOW() AS data_access_expired,
				DATEDIFF(c.expires_at, NOW()) AS days_left, DATEDIFF(c.data_access_expires_at, NOW()) AS data_access_days_left
			FROM social_accounts a
			LEFT JOIN account_credentials c ON c.social_account_id = a.id
			WHERE a.status = ?
			ORDER BY a.id',
			AccountStatus::Active->value,
		)->fetchAll();

		$alarms = [];
		foreach ($rows as $row) {
			$account = (string) $row->name;
			$alarm = match (true) {
				$row->credential === null => new Alarm('token_missing', ['account' => $account]),
				$row->status === TokenStatus::Invalid->value => new Alarm('token_invalid', ['account' => $account]),
				(bool) $row->expired || (bool) $row->data_access_expired => new Alarm('token_expired', ['account' => $account]),
				$days !== null && $row->days_left !== null && $row->days_left <= $days => new Alarm('token_expiring', [
					'account' => $account,
					'days' => (int) $row->days_left,
				]),
				$days !== null && $row->data_access_days_left !== null && $row->data_access_days_left <= $days => new Alarm('data_access_expiring', [
					'account' => $account,
					'days' => (int) $row->data_access_days_left,
				]),
				default => null,
			};
			if ($alarm !== null) {
				$alarms[] = $alarm;
			}
		}

		return $alarms;
	}


	/**
	 * @return list<Alarm>
	 */
	private function silence(?int $hours): array
	{
		if ($hours === null) {
			return [];
		}

		$rows = $this->database->query(
			'SELECT a.name, TIMESTAMPDIFF(HOUR, MAX(e.received_at), NOW()) AS hours
			FROM social_accounts a
			JOIN social_events e ON e.account_id = a.id
			WHERE a.status = ?
			GROUP BY a.id, a.name
			HAVING MAX(e.received_at) < NOW() - INTERVAL ? HOUR
			ORDER BY a.id',
			AccountStatus::Active->value,
			$hours,
		)->fetchAll();

		return array_map(fn($row) => new Alarm('webhook_silence', [
			'account' => (string) $row->name,
			'hours' => (int) $row->hours,
		]), $rows);
	}


	/**
	 * @return list<Alarm>
	 */
	private function queue(?int $minutes): array
	{
		if ($minutes === null) {
			return [];
		}

		$alarms = [];
		$lag = $this->database->query(
			'SELECT TIMESTAMPDIFF(MINUTE, MIN(available_at), NOW(3)) FROM queue_jobs WHERE status = ? AND available_at <= NOW(3)',
			JobStatus::Pending->value,
		)->fetchField();
		if ($lag !== null && (int) $lag >= $minutes) {
			$alarms[] = new Alarm('queue_lag', ['minutes' => (int) $lag]);
		}

		$heartbeat = is_file($this->heartbeatFile) ? filemtime($this->heartbeatFile) : false;
		if ($heartbeat === false) {
			$alarms[] = new Alarm('worker_missing');
		} elseif (time() - $heartbeat >= $minutes * 60) {
			$alarms[] = new Alarm('worker_silent', ['minutes' => intdiv(time() - $heartbeat, 60)]);
		}

		return $alarms;
	}


	/**
	 * @return list<Alarm>
	 */
	private function failures(?int $count): array
	{
		$alarms = array_map(
			fn($name) => new Alarm('circuit_open', ['api' => (string) $name]),
			array_values($this->database->query('SELECT name FROM circuit_breakers WHERE opened_until > NOW(3) ORDER BY name')->fetchPairs(null, 'name')),
		);
		if ($count === null) {
			return $alarms;
		}

		$jobs = (int) $this->database->query(
			'SELECT COUNT(*) FROM queue_jobs WHERE status = ? AND updated_at >= NOW() - INTERVAL 24 HOUR',
			JobStatus::Failed->value,
		)->fetchField();
		if ($jobs >= $count) {
			$alarms[] = new Alarm('failed_jobs', ['count' => $jobs]);
		}
		$actions = (int) $this->database->query(
			'SELECT COUNT(*) FROM moderation_actions WHERE status = ? AND updated_at >= NOW(3) - INTERVAL 24 HOUR',
			ActionStatus::Failed->value,
		)->fetchField();
		if ($actions >= $count) {
			$alarms[] = new Alarm('failed_actions', ['count' => $actions]);
		}

		return $alarms;
	}


	/**
	 * @return list<Alarm>
	 */
	private function actions(?int $hides, ?int $hours): array
	{
		$alarms = [];
		$unknown = (int) $this->database->query('SELECT COUNT(*) FROM moderation_actions WHERE status = ?', ActionStatus::Unknown->value)->fetchField();
		if ($unknown > 0) {
			$alarms[] = new Alarm('unknown_actions', ['count' => $unknown]);
		}
		if ($hides !== null) {
			$hidden = (int) $this->database->query(
				'SELECT COUNT(*) FROM moderation_actions WHERE type = ? AND created_at >= NOW(3) - INTERVAL 24 HOUR',
				ActionType::Hide->value,
			)->fetchField();
			if ($hidden >= $hides) {
				$alarms[] = new Alarm('hidden_comments', ['count' => $hidden]);
			}
		}
		// In observe mode nothing can be approved, so waiting actions are expected.
		if ($hours !== null && $this->policies->active()->mode !== Mode::Observe) {
			$stale = (int) $this->database->query(
				'SELECT COUNT(*) FROM moderation_actions WHERE status = ? AND created_at < NOW(3) - INTERVAL ? HOUR',
				ActionStatus::Pending->value,
				$hours,
			)->fetchField();
			if ($stale > 0) {
				$alarms[] = new Alarm('stale_approvals', ['count' => $stale, 'hours' => $hours]);
			}
		}

		return $alarms;
	}
}
