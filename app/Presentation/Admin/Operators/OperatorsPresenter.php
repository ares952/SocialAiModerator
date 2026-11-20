<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Operators;

use App\Operator\Authorization;
use App\Operator\InvalidOperator;
use App\Operator\OperatorAuthenticator;
use App\Operator\Operators;
use App\Operator\Role;
use App\Presentation\Admin\AdminPresenter;
use Nette\Application\UI\Form;
use Nette\Database\Row;


/**
 * Operators of the administration (admin only). Every change is audited by Operators.
 */
final class OperatorsPresenter extends AdminPresenter
{
	private ?Row $operator = null;


	public function __construct(
		private readonly Operators $operators,
		private readonly OperatorAuthenticator $authenticator,
	) {
		parent::__construct();
	}


	protected function startup(): void
	{
		parent::startup();
		$this->requireAllowed(Authorization::Operators, Authorization::Manage);
	}


	public function renderDefault(): void
	{
		$this->template->setParameters([
			'operators' => $this->operators->all(),
		]);
	}


	public function actionEdit(int $id): void
	{
		$this->operator = $this->operators->find($id) ?? $this->error();
		$form = $this->getComponent('editForm');
		assert($form instanceof Form);
		$form->setDefaults([
			'name' => $this->operator->name,
			'role' => $this->operator->role,
			'active' => (bool) $this->operator->active,
		]);
		$this->template->setParameters([
			'operator' => $this->operator,
		]);
	}


	protected function createComponentAddForm(): Form
	{
		$form = $this->forms->create();
		$form->addText('email', 'operator.form.email')
			->setHtmlType('email')
			->setRequired('form.error.required')
			->addRule(Form::Email, 'form.error.email')
			->setMaxLength(254);
		$this->addProfileControls($form);
		$this->addPasswordControls($form);
		$form->addSubmit('send', 'operator.form.create');

		$form->onSuccess[] = function (Form $form): void {
			$values = $form->getValues();
			try {
				$this->operators->create(
					(string) $values['email'],
					(string) $values['name'],
					Role::from((string) $values['role']),
					(string) $values['password'],
					$this->operatorId(),
				);
			} catch (InvalidOperator $e) {
				$form->addError($e->getMessage());

				return;
			}

			$this->flashMessage('operator.created', 'success');
			$this->redirect('default');
		};

		return $form;
	}


	protected function createComponentEditForm(): Form
	{
		$form = $this->forms->create();
		$this->addProfileControls($form);
		$form->addCheckbox('active', 'operator.form.active');
		$form->addSubmit('send', 'operator.form.save');

		$form->onSuccess[] = function (Form $form): void {
			$values = $form->getValues();
			$id = $this->editedId();
			try {
				$this->operators->update(
					$id,
					(string) $values['name'],
					Role::from((string) $values['role']),
					(bool) $values['active'],
					$this->operatorId(),
				);
			} catch (InvalidOperator $e) {
				$form->addError($e->getMessage());

				return;
			}

			$this->renewOwnIdentity($id);
			$this->flashMessage('operator.updated', 'success');
			$this->redirect('default');
		};

		return $form;
	}


	protected function createComponentPasswordForm(): Form
	{
		$form = $this->forms->create();
		$this->addPasswordControls($form);
		$form->addSubmit('send', 'operator.form.set-password');

		$form->onSuccess[] = function (Form $form): void {
			$values = $form->getValues();
			$id = $this->editedId();
			try {
				$this->operators->changePassword($id, (string) $values['password'], $this->operatorId());
			} catch (InvalidOperator $e) {
				$form->addError($e->getMessage());

				return;
			}

			$this->renewOwnIdentity($id);
			$this->flashMessage('operator.password-set', 'success');
			$this->redirect('default');
		};

		return $form;
	}


	private function addProfileControls(Form $form): void
	{
		$form->addText('name', 'operator.form.name')
			->setRequired('form.error.required')
			->setMaxLength(100);
		$roles = [];
		foreach (Role::cases() as $role) {
			$roles[$role->value] = $role->label();
		}
		$form->addSelect('role', 'operator.form.role', $roles)
			->setRequired('form.error.required');
	}


	private function addPasswordControls(Form $form): void
	{
		$password = $form->addPassword('password', 'operator.form.password')
			->setRequired('form.error.required')
			->addRule(Form::MinLength, 'form.error.password-length', Operators::MinPasswordLength)
			->setHtmlAttribute('autocomplete', 'new-password');
		$form->addPassword('passwordCheck', 'operator.form.password-check')
			->setRequired('form.error.required')
			->addRule(Form::Equal, 'form.error.password-mismatch', $password)
			->setOmitted()
			->setHtmlAttribute('autocomplete', 'new-password');
	}


	private function editedId(): int
	{
		return $this->operator === null ? $this->error() : (int) $this->operator->id;
	}


	/**
	 * Changing one's own account raises the session version; keep the current session valid.
	 */
	private function renewOwnIdentity(int $id): void
	{
		if ($id !== $this->operatorId()) {
			return;
		}

		$identity = $this->authenticator->identity($id);
		if ($identity === null) {
			$this->getUser()->logout(clearIdentity: true);
			$this->redirect(':Sign:in');
		}
		$this->getUser()->login($identity);
	}
}
