<?php

declare(strict_types=1);

namespace App\Presentation\Sign;

use App\Presentation\BasePresenter;
use App\Presentation\FormFactory;
use Nette\Application\Attributes\Persistent;
use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;
use Nette\Security\AuthenticationException;


final class SignPresenter extends BasePresenter
{
	/** Request to return to after signing in (stored by AdminPresenter). */
	#[Persistent]
	public string $backlink = '';


	public function __construct(
		private readonly FormFactory $forms,
	) {
		parent::__construct();
	}


	public function actionIn(): void
	{
		if ($this->getUser()->isLoggedIn()) {
			$this->redirect(':Admin:Dashboard:');
		}
	}


	/**
	 * Same-origin only, so a foreign page cannot sign the operator out.
	 */
	#[Requires(sameOrigin: true)]
	public function actionOut(): void
	{
		$this->getUser()->logout(clearIdentity: true);
		$this->flashMessage('sign.out.done', 'success');
		$this->redirect('in');
	}


	protected function createComponentSignInForm(): Form
	{
		$form = $this->forms->create();
		$form->addText('email', 'sign.in.email')
			->setHtmlType('email')
			->setRequired('form.error.required')
			->addRule(Form::Email, 'form.error.email')
			->setHtmlAttribute('autocomplete', 'username')
			->setHtmlAttribute('autofocus');
		$form->addPassword('password', 'sign.in.password')
			->setRequired('form.error.required')
			->setHtmlAttribute('autocomplete', 'current-password');
		$form->addSubmit('send', 'sign.in.submit');

		$form->onSuccess[] = function (Form $form): void {
			$values = $form->getValues();
			try {
				$this->getUser()->login((string) $values['email'], (string) $values['password']);
			} catch (AuthenticationException $e) {
				$form->addError($e->getMessage());

				return;
			}

			$this->restoreRequest($this->backlink);
			$this->redirect(':Admin:Dashboard:');
		};

		return $form;
	}
}
