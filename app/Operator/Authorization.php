<?php

declare(strict_types=1);

namespace App\Operator;

use Nette\Security\Permission;
use Nette\StaticClass;


/**
 * Role matrix of the administration, checked on the server by every presenter
 * ($user->isAllowed(resource, privilege)). Each role inherits the one below.
 *
 * - viewer: reads the dashboard, events, policy, knowledge and evaluation results;
 * - moderator: also edits knowledge drafts and the evaluation samples (and sees their texts);
 * - admin: everything, including publishing knowledge, policies, the panic switch, evaluation
 *   runs and operators.
 */
final class Authorization
{
	use StaticClass;

	public const Dashboard = 'dashboard';
	public const Events = 'events';
	public const Operators = 'operators';
	public const Policy = 'policy';
	public const Knowledge = 'knowledge';
	public const Evaluation = 'evaluation';

	public const View = 'view';
	public const Edit = 'edit';
	public const Manage = 'manage';


	public static function create(): Permission
	{
		$acl = new Permission;
		$acl->addRole(Role::Viewer->value);
		$acl->addRole(Role::Moderator->value, Role::Viewer->value);
		$acl->addRole(Role::Admin->value, Role::Moderator->value);

		$readable = [
			self::Dashboard,
			self::Events,
			self::Policy,
			self::Knowledge,
			self::Evaluation,
		];
		foreach ([...$readable, self::Operators] as $resource) {
			$acl->addResource($resource);
		}

		$acl->allow(Role::Viewer->value, $readable, self::View);
		$acl->allow(Role::Moderator->value, [
			self::Knowledge,
			self::Evaluation,
		], self::Edit);
		$acl->allow(Role::Admin->value);

		return $acl;
	}
}
