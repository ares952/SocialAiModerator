<?php

declare(strict_types=1);

namespace App\Operator;

use Nette\Security\Permission;
use Nette\StaticClass;


/**
 * Role matrix of the administration, checked on the server by every presenter
 * ($user->isAllowed(resource, privilege)). Each role inherits the one below.
 *
 * - viewer: reads the dashboard, events, moderation actions, accounts, policy, knowledge and
 *   evaluation results;
 * - moderator: also edits knowledge drafts and the evaluation samples (and sees their texts),
 *   reads the customer content of events with the AI explanation (Events + Edit), and approves,
 *   rejects or requests hiding and unhiding of comments (Actions + Edit);
 * - admin: everything, including publishing knowledge, policies, the panic switch, evaluation
 *   runs, accounts, operators, the audit log and the retention periods.
 */
final class Authorization
{
	use StaticClass;

	public const Dashboard = 'dashboard';
	public const Events = 'events';
	public const Actions = 'actions';
	public const Operators = 'operators';
	public const Policy = 'policy';
	public const Knowledge = 'knowledge';
	public const Evaluation = 'evaluation';
	public const Accounts = 'accounts';
	public const Audit = 'audit';
	public const Retention = 'retention';

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
			self::Actions,
			self::Policy,
			self::Knowledge,
			self::Evaluation,
			self::Accounts,
		];
		foreach ([...$readable, self::Operators, self::Audit, self::Retention] as $resource) {
			$acl->addResource($resource);
		}

		$acl->allow(Role::Viewer->value, $readable, self::View);
		$acl->allow(Role::Moderator->value, [
			self::Knowledge,
			self::Evaluation,
			self::Events,
			self::Actions,
		], self::Edit);
		$acl->allow(Role::Admin->value);

		return $acl;
	}
}
