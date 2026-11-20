<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Password;

use App\Operator\InvalidOperator;
use App\Operator\OperatorAuthenticator;
use App\Operator\Operators;
use App\Presentation\Admin\AdminPresenter;
use Nette\Application\UI\Form;


/**
 * Own password of the signed-in operator (every role). Other sessions are signed out.
 */
final class PasswordPresenter extends AdminPresenter
{
	public function __construct(
		private readonly Operators $operators,
		private readonly OperatorAuthenticator $authenticator,
	) {
		parent::__construct();
	}


	protected function createComponentPasswordForm(): Form
	{
		$form = $this->forms->create();
		$current = $form->addPassword('current', 'password.current')
			->setRequired('form.error.required')
			->setHtmlAttribute('autocomplete', 'current-password');
		$password = $form->addPassword('password', 'password.new')
			->setRequired('form.error.required')
			->addRule(Form::MinLength, 'form.error.password-length', Operators::MinPasswordLength)
			->setHtmlAttribute('autocomplete', 'new-password');
		$form->addPassword('passwordCheck', 'password.check')
			->setRequired('form.error.required')
			->addRule(Form::Equal, 'form.error.password-mismatch', $password)
			->setOmitted()
			->setHtmlAttribute('autocomplete', 'new-password');
		$form->addSubmit('send', 'password.submit');

		$form->onSuccess[] = function (Form $form) use ($current): void {
			$values = $form->getValues();
			$id = $this->operatorId();
			if (!$this->operators->verifyPassword($id, (string) $values['current'])) {
				$current->addError('password.error.current');

				return;
			}

			try {
				$this->operators->changePassword($id, (string) $values['password'], $id);
			} catch (InvalidOperator $e) {
				$form->addError($e->getMessage());

				return;
			}

			// The version changed: sign in again here, all other sessions are signed out.
			$this->getUser()->login($this->authenticator->identity($id) ?? $this->error('', 403));
			$this->flashMessage('password.changed', 'success');
			$this->redirect(':Admin:Dashboard:');
		};

		return $form;
	}
}
