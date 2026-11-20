<?php

declare(strict_types=1);

namespace Tests\Support;

use Nette\Caching\Storages\DevNullStorage;
use Nette\Database\Connection;
use Nette\Database\Conventions\DiscoveredConventions;
use Nette\Database\Explorer;
use Nette\Database\Structure;
use Nette\Security\Passwords;
use Tester\Environment;


/**
 * Direct access to the throwaway database of the e2e stack, for preparing operators and checking
 * results. Refuses to run anywhere else.
 */
final class E2eData
{
	public readonly Explorer $database;


	public function __construct()
	{
		if (getenv('E2E') !== '1') {
			Environment::skip('End-to-end tests run only in the e2e stack (bin/e2e).');
		}

		$connection = new Connection(
			'mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
			(string) getenv('DB_USER'),
			(string) getenv('DB_PASSWORD'),
		);
		$structure = new Structure($connection, new DevNullStorage);
		$this->database = new Explorer($connection, $structure, new DiscoveredConventions($structure));
	}


	/**
	 * Creates an operator with a unique e-mail.
	 *
	 * @return array{string, string} e-mail and password
	 */
	public function operator(string $role): array
	{
		$email = $role . '-' . bin2hex(random_bytes(4)) . '@example.test';
		$password = bin2hex(random_bytes(12));
		$this->database->query('INSERT INTO operators ?', [
			'email' => $email,
			'name' => ucfirst($role) . ' E2E',
			'role' => $role,
			'password_hash' => (new Passwords(PASSWORD_BCRYPT, ['cost' => 4]))->hash($password),
		]);

		return [$email, $password];
	}


	/**
	 * Exception bluescreens written by php-fpm in this container (log/ is the stack's tmpfs).
	 *
	 * @return list<string>
	 */
	public static function exceptions(): array
	{
		return array_map('basename', glob(dirname(__DIR__, 2) . '/log/exception--*.html') ?: []);
	}
}
