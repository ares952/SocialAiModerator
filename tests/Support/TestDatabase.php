<?php

declare(strict_types=1);

namespace Tests\Support;

use Nette\Caching\Storages\DevNullStorage;
use Nette\Database\Connection;
use Nette\Database\Conventions\DiscoveredConventions;
use Nette\Database\Explorer;
use Nette\Database\Structure;
use Nette\StaticClass;
use Nextras\Migrations\Bridges\NetteDatabase\NetteAdapter;
use Nextras\Migrations\Drivers\MySqlDriver;
use Nextras\Migrations\Engine\Runner;
use Nextras\Migrations\Entities\Group;
use Nextras\Migrations\Extensions\SqlHandler;
use Nextras\Migrations\Printers\DevNull;
use Tester\Environment;


/**
 * Fresh schema in the dedicated test database (<DB_NAME>_test, created by
 * deploy/mariadb/init). Never connects to the application database.
 */
final class TestDatabase
{
	use StaticClass;

	public static function create(): Explorer
	{
		// Integration tests share one database; Tester runs test files in parallel.
		Environment::lock('database', dirname(__DIR__, 2) . '/temp/tests');

		$connection = self::connection();

		$driver = new MySqlDriver(new NetteAdapter($connection));
		$runner = new Runner($driver, new DevNull);
		$runner->addExtensionHandler('sql', new SqlHandler($driver));
		foreach (['structures', 'basic-data'] as $groupName) {
			$group = new Group;
			$group->name = $groupName;
			$group->enabled = true;
			$group->directory = dirname(__DIR__, 2) . "/migrations/$groupName";
			$group->dependencies = $groupName === 'basic-data' ? ['structures'] : [];
			$runner->addGroup($group);
		}
		$runner->run(Runner::MODE_RESET);

		return self::explorer(self::utc($connection));
	}


	/**
	 * Second connection to the database prepared by create(), e.g. for a child process of a
	 * concurrency test. Never resets anything.
	 */
	public static function connect(): Explorer
	{
		return self::explorer(self::utc(self::connection()));
	}


	private static function connection(): Connection
	{
		return new Connection(
			'mysql:host=' . self::env('DB_HOST') . ';dbname=' . self::env('DB_NAME') . '_test;charset=utf8mb4',
			self::env('DB_USER'),
			self::env('DB_PASSWORD'),
		);
	}


	/**
	 * The session settings of the application: the server default time zone (UTC,
	 * deploy/mariadb/my.cnf) and foreign key checks. The migration runner switches the shared
	 * connection to SYSTEM time and turns the foreign key checks off.
	 */
	private static function utc(Connection $connection): Connection
	{
		$connection->query("SET time_zone = '+00:00'");
		$connection->query('SET foreign_key_checks = 1');

		return $connection;
	}


	private static function explorer(Connection $connection): Explorer
	{
		$structure = new Structure($connection, new DevNullStorage);

		return new Explorer($connection, $structure, new DiscoveredConventions($structure));
	}


	private static function env(string $name): string
	{
		$value = getenv($name);
		if ($value === false || $value === '') {
			throw new \RuntimeException("Environment variable $name is not set; run the tests in the php container.");
		}

		return $value;
	}
}
