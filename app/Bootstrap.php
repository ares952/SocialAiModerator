<?php

declare(strict_types=1);

namespace App;

use Nette\Bootstrap\Configurator;
use Nette\DI\Container;
use Nette\Utils\FileSystem;


final class Bootstrap
{
	private readonly Configurator $configurator;
	private readonly string $rootDir;


	public function __construct()
	{
		$this->rootDir = dirname(__DIR__);
		$this->configurator = new Configurator;
		$this->configurator->setTempDirectory($this->rootDir . '/temp');
	}


	public function bootWebApplication(): Container
	{
		$this->configurator->setDebugMode(getenv('APP_DEBUG') === '1');
		$this->configurator->enableTracy($this->rootDir . '/log');

		return $this->createContainer();
	}


	public function bootConsoleApplication(): Container
	{
		$this->configurator->setDebugMode(false);
		$this->configurator->enableTracy($this->rootDir . '/log');

		return $this->createContainer();
	}


	private function createContainer(): Container
	{
		$configDir = $this->rootDir . '/config';

		$this->configurator->addStaticParameters([
			'rootDir' => $this->rootDir,
			'appVersion' => trim(FileSystem::read($this->rootDir . '/version.txt')),
		]);
		// Dynamic, so secrets from the environment are never compiled into temp/cache.
		$this->configurator->addDynamicParameters([
			'env' => getenv(),
		]);
		$this->configurator->addConfig($configDir . '/common.neon');
		$this->configurator->addConfig($configDir . '/services.neon');
		$this->configurator->addConfig($configDir . '/local.neon');

		return $this->configurator->createContainer();
	}
}
