<?php

declare(strict_types=1);

namespace App\Operator;

use Nette\Security\Permission;
use Nette\StaticClass;


/**
 * Role matrix of the administration, checked on the server by every presenter
 * ($user->isAllowed(resource, privilege)). Each role inherits the one below.
 */
final class Authorization
{
	use StaticClass;

	public const Dashboard = 'dashboard';
	public const Events = 'events';
	public const Operators = 'operators';

	public const View = 'view';
	public const Manage = 'manage';


	public static function create(): Permission
	{
		$acl = new Permission;
		$acl->addRole(Role::Viewer->value);
		$acl->addRole(Role::Moderator->value, Role::Viewer->value);
		$acl->addRole(Role::Admin->value, Role::Moderator->value);

		$acl->addResource(self::Dashboard);
		$acl->addResource(self::Events);
		$acl->addResource(self::Operators);

		$acl->allow(Role::Viewer->value, self::Dashboard, self::View);
		$acl->allow(Role::Viewer->value, self::Events, self::View);
		$acl->allow(Role::Admin->value);

		return $acl;
	}
}
