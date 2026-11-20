<?php

declare(strict_types=1);

namespace App\Core;

use Nette\Application\Routers\RouteList;
use Nette\StaticClass;


final class RouterFactory
{
	use StaticClass;

	/**
	 * @param list<string> $languages supported UI languages, the first one is the default
	 */
	public static function createRouter(array $languages): RouteList
	{
		$lang = '[<lang=' . $languages[0] . ' ' . implode('|', $languages) . '>/]';

		$router = new RouteList;
		$router->addRoute('health/live', 'Health:live');
		$router->addRoute('health/ready', 'Health:ready');
		$router->addRoute('api/meta/webhook', 'Api:MetaWebhook:default');
		$router->addRoute($lang . 'sign/in', 'Sign:in');
		$router->addRoute($lang . 'sign/out', 'Sign:out');
		// The homepage is the dashboard; the administration always requires signing in.
		$router->addRoute($lang, 'Admin:Dashboard:default');
		$router->withModule('Admin')
			->addRoute($lang . 'admin/<presenter>[/<action>][/<id \d+>]', [
				'action' => 'default',
			]);

		return $router;
	}
}
