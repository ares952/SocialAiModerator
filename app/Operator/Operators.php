<?php

declare(strict_types=1);

namespace App\Operator;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use Nette\Database\Explorer;
use Nette\Database\Row;
use Nette\Database\UniqueConstraintViolationException;
use Nette\Security\Passwords;
use Nette\Utils\Validators;


/**
 * Administration accounts. Every change is audited with the acting operator (NULL = console).
 * At least one active admin always remains, so the administration cannot lock itself out.
 */
final class Operators
{
	public const MinPasswordLength = 12;


	public function __construct(
		private readonly Explorer $database,
		private readonly Passwords $passwords,
		private readonly AuditLog $audit,
	) {
	}


	public static function normalizeEmail(string $email): string
	{
		return mb_strtolower(trim($email));
	}


	/**
	 * @throws InvalidOperator
	 */
	public function create(
		string $email,
		string $name,
		Role $role,
		#[\SensitiveParameter] string $password,
		?int $actorId,
	): int {
		$email = self::normalizeEmail($email);
		if (!Validators::isEmail($email)) {
			throw new InvalidOperator('operator.error.email');
		}
		self::checkPassword($password);

		try {
			$this->database->query('INSERT INTO operators ?', [
				'email' => $email,
				'name' => trim($name),
				'role' => $role->value,
				'password_hash' => $this->passwords->hash($password),
			]);
		} catch (UniqueConstraintViolationException) {
			throw new InvalidOperator('operator.error.email-taken');
		}

		$id = (int) $this->database->getInsertId();
		$this->audit->write($actorId, AuditAction::OperatorCreate, 'operator', $id, "role=$role->value");

		return $id;
	}


	/**
	 * Deactivation signs the operator out of all sessions.
	 *
	 * @throws InvalidOperator
	 */
	public function update(int $id, string $name, Role $role, bool $active, ?int $actorId): void
	{
		$this->database->transaction(function () use ($id, $name, $role, $active, $actorId): void {
			// Locks the active admins, so two concurrent changes cannot remove the last one.
			$admins = $this->database->query(
				'SELECT id FROM operators WHERE role = ? AND active = 1 FOR UPDATE',
				Role::Admin->value,
			)->fetchPairs(null, 'id');
			$current = $this->database->query('SELECT role, active FROM operators WHERE id = ? FOR UPDATE', $id)->fetch()
				?? throw new InvalidOperator('operator.error.not-found');

			$losesAdmin = in_array($id, $admins, true) && ($role !== Role::Admin || !$active);
			if ($losesAdmin && count($admins) === 1) {
				throw new InvalidOperator('operator.error.last-admin');
			}

			$this->database->query(
				'UPDATE operators SET name = ?, role = ?, active = ?, session_version = session_version + ? WHERE id = ?',
				trim($name),
				$role->value,
				$active,
				(bool) $current->active && !$active ? 1 : 0,
				$id,
			);
			$this->audit->write(
				$actorId,
				AuditAction::OperatorUpdate,
				'operator',
				$id,
				"role=$current->role->$role->value, active=" . (int) $current->active . '->' . (int) $active,
			);
		});
	}


	/**
	 * Signs the operator out of all sessions (the caller renews its own identity).
	 *
	 * @throws InvalidOperator
	 */
	public function changePassword(int $id, #[\SensitiveParameter] string $password, ?int $actorId): void
	{
		self::checkPassword($password);
		$changed = $this->database->query(
			'UPDATE operators SET password_hash = ?, session_version = session_version + 1 WHERE id = ?',
			$this->passwords->hash($password),
			$id,
		)->getRowCount();
		if ($changed !== 1) {
			throw new InvalidOperator('operator.error.not-found');
		}

		$this->audit->write($actorId, AuditAction::OperatorPassword, 'operator', $id);
	}


	public function verifyPassword(int $id, #[\SensitiveParameter] string $password): bool
	{
		$hash = $this->database->query('SELECT password_hash FROM operators WHERE id = ?', $id)->fetchField();

		return is_string($hash) && $this->passwords->verify($password, $hash);
	}


	public function find(int $id): ?Row
	{
		return $this->database->query(
			'SELECT id, email, name, role, active, last_login_at, created_at FROM operators WHERE id = ?',
			$id,
		)->fetch();
	}


	public function findByEmail(string $email): ?Row
	{
		return $this->database->query(
			'SELECT id, email, name, role, active, last_login_at, created_at FROM operators WHERE email = ?',
			self::normalizeEmail($email),
		)->fetch();
	}


	/**
	 * @return list<Row>
	 */
	public function all(): array
	{
		return $this->database->query(
			'SELECT id, email, name, role, active, last_login_at, created_at FROM operators ORDER BY active DESC, name',
		)->fetchAll();
	}


	/**
	 * @throws InvalidOperator
	 */
	private static function checkPassword(#[\SensitiveParameter] string $password): void
	{
		if (mb_strlen($password) < self::MinPasswordLength) {
			throw new InvalidOperator('operator.error.password-length');
		}
	}
}
