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

		$name = self::env('DB_NAME') . '_test';
		$connection = new Connection(
			'mysql:host=' . self::env('DB_HOST') . ";dbname=$name;charset=utf8mb4",
			self::env('DB_USER'),
			self::env('DB_PASSWORD'),
		);

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
