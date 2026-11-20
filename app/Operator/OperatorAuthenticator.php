<?php

declare(strict_types=1);

namespace App\Operator;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use Nette\Database\Explorer;
use Nette\Http\IRequest;
use Nette\Security\AuthenticationException;
use Nette\Security\Authenticator;
use Nette\Security\IdentityHandler;
use Nette\Security\IIdentity;
use Nette\Security\Passwords;
use Nette\Security\SimpleIdentity;


/**
 * Sign-in of operators and validation of every stored session: the identity is reloaded from
 * the database on each request, so a deactivated operator, a changed role or a changed password
 * takes effect immediately (session_version).
 */
final class OperatorAuthenticator implements Authenticator, IdentityHandler
{
	/**
	 * Hash of a random discarded password with the default cost; verified for unknown e-mails
	 * too, so the response time does not reveal which accounts exist.
	 */
	private const DummyHash = '$2y$12$HuGjGncilttcwXVaG25GMeKbN1nXWHoYLduqlXAI8TTpjzD1gn4EW';


	public function __construct(
		private readonly Explorer $database,
		private readonly Passwords $passwords,
		private readonly LoginThrottle $throttle,
		private readonly AuditLog $audit,
		private readonly IRequest $httpRequest,
	) {
	}


	/**
	 * @throws AuthenticationException message is a translation key
	 */
	public function authenticate(string $user, #[\SensitiveParameter] string $password): IIdentity
	{
		$email = Operators::normalizeEmail($user);
		$address = $this->httpRequest->getRemoteAddress() ?? 'unknown';
		if ($this->throttle->isBlocked($email, $address)) {
			throw new AuthenticationException('sign.in.throttled', self::NotApproved);
		}

		$operator = $this->database->query(
			'SELECT id, password_hash FROM operators WHERE email = ? AND active = 1',
			$email,
		)->fetch();
		$valid = $this->passwords->verify($password, $operator->password_hash ?? self::DummyHash);
		if ($operator === null || !$valid) {
			$this->throttle->recordFailure($email, $address);
			throw new AuthenticationException('sign.in.failed', self::InvalidCredential);
		}

		if ($this->passwords->needsRehash($operator->password_hash)) {
			$this->database->query(
				'UPDATE operators SET password_hash = ? WHERE id = ?',
				$this->passwords->hash($password),
				$operator->id,
			);
		}
		$this->database->query('UPDATE operators SET last_login_at = NOW() WHERE id = ?', $operator->id);
		$this->throttle->clear($email);
		$this->audit->write($operator->id, AuditAction::Login, 'operator', $operator->id);

		return $this->identity($operator->id)
			?? throw new AuthenticationException('sign.in.failed', self::InvalidCredential);
	}


	/**
	 * Current identity of an active operator, or null.
	 */
	public function identity(int $id): ?SimpleIdentity
	{
		$operator = $this->database->query(
			'SELECT id, email, name, role, session_version FROM operators WHERE id = ? AND active = 1',
			$id,
		)->fetch();
		if ($operator === null) {
			return null;
		}

		return new SimpleIdentity($operator->id, [$operator->role], [
			'email' => $operator->email,
			'name' => $operator->name,
			'version' => $operator->session_version,
		]);
	}


	/**
	 * The session keeps only the ID and the session version.
	 */
	public function sleepIdentity(IIdentity $identity): IIdentity
	{
		return new SimpleIdentity($identity->getId(), [], [
			'version' => $identity->getData()['version'] ?? null,
		]);
	}


	public function wakeupIdentity(IIdentity $identity): ?IIdentity
	{
		$id = $identity->getId();
		$current = is_int($id) ? $this->identity($id) : null;

		return $current !== null && $current->getData()['version'] === ($identity->getData()['version'] ?? null)
			? $current
			: null;
	}
}
