<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Presentation\BasePresenter;
use App\Presentation\FormFactory;
use Nette\Security\UserStorage;


/**
 * Base of every administration page: requires a signed-in operator; each presenter checks its
 * resource and privilege on the server (App\Operator\Authorization).
 */
abstract class AdminPresenter extends BasePresenter
{
	protected FormFactory $forms;


	public function injectForms(FormFactory $forms): void
	{
		$this->forms = $forms;
	}


	public function checkRequirements(\ReflectionClass|\ReflectionMethod $element): void
	{
		parent::checkRequirements($element);

		$user = $this->getUser();
		if (!$user->isLoggedIn()) {
			if ($user->getLogoutReason() === UserStorage::LOGOUT_INACTIVITY) {
				$this->flashMessage('sign.in.expired', 'warning');
			}
			$this->redirect(':Sign:in', [
				'backlink' => $this->storeRequest(),
			]);
		}
	}


	protected function requireAllowed(string $resource, string $privilege): void
	{
		if (!$this->getUser()->isAllowed($resource, $privilege)) {
			$this->error('', 403);
		}
	}


	protected function operatorId(): int
	{
		$id = $this->getUser()->getId();
		if (!is_int($id)) {
			throw new \LogicException('No operator is signed in.');
		}

		return $id;
	}
}
