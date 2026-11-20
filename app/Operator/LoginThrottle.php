<?php

declare(strict_types=1);

namespace App\Operator;

use Nette\Database\Explorer;


/**
 * Limits failed sign-ins per e-mail and per client address within a time window.
 */
final class LoginThrottle
{
	public function __construct(
		private readonly Explorer $database,
		private readonly int $maxPerEmail,
		private readonly int $maxPerAddress,
		private readonly int $windowMinutes,
	) {
	}


	public function isBlocked(string $email, string $address): bool
	{
		$counts = $this->database->query(
			'SELECT SUM(email = ?) AS by_email, SUM(ip_address = ?) AS by_address
			FROM login_failures
			WHERE (email = ? OR ip_address = ?) AND created_at > NOW() - INTERVAL ? MINUTE',
			$email,
			$address,
			$email,
			$address,
			$this->windowMinutes,
		)->fetch();

		return (int) $counts?->by_email >= $this->maxPerEmail
			|| (int) $counts?->by_address >= $this->maxPerAddress;
	}


	public function recordFailure(string $email, string $address): void
	{
		$this->database->query(
			'DELETE FROM login_failures WHERE created_at < NOW() - INTERVAL ? MINUTE',
			$this->windowMinutes,
		);
		$this->database->query('INSERT INTO login_failures ?', [
			'email' => $email,
			'ip_address' => $address,
		]);
	}


	public function clear(string $email): void
	{
		$this->database->query('DELETE FROM login_failures WHERE email = ?', $email);
	}
}
