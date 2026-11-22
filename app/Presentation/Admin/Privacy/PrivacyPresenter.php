<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Privacy;

use App\Operator\Authorization;
use App\Presentation\Admin\AdminPresenter;
use App\Privacy\CustomerData;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Form;
use Nette\Utils\Json;


/**
 * Data subject requests (specification §8.3, admin only): look up what is stored about a customer,
 * export it as JSON, delete it. The customer ID travels only in POST bodies, never in URLs, so it
 * does not end up in access logs.
 */
final class PrivacyPresenter extends AdminPresenter
{
	private ?string $customer = null;


	public function __construct(
		private readonly CustomerData $data,
	) {
		parent::__construct();
	}


	public function actionDefault(): void
	{
		$this->requireAllowed(Authorization::Privacy, Authorization::Manage);
	}


	public function renderDefault(): void
	{
		$this->template->setParameters([
			'customer' => $this->customer,
			'summary' => $this->customer === null ? null : $this->data->summary($this->customer),
		]);
	}


	protected function createComponentSearchForm(): Form
	{
		$form = $this->forms->create();
		$this->customerField($form, 'customer')
			->setOption('description', 'privacy.customer-note');
		$form->addSubmit('send', 'privacy.search');
		$form->onSuccess[] = function (Form $form): void {
			// shown in this response; no redirect, so the ID stays out of the URL
			$this->customer = (string) $form->getValues()['customer'];
			foreach (['exportForm', 'deleteForm'] as $name) {
				$target = $this->getComponent($name);
				assert($target instanceof Form);
				$target->setDefaults([
					'customer' => $this->customer,
				]);
			}
		};

		return $form;
	}


	protected function createComponentExportForm(): Form
	{
		$form = $this->forms->create();
		$form->addHidden('customer')
			->addRule(fn($control): bool => CustomerData::isValidId((string) $control->getValue()), 'privacy.error.id');
		$form->addSubmit('send', 'privacy.export');
		$form->onSuccess[] = function (Form $form): void {
			$this->requireAllowed(Authorization::Privacy, Authorization::Manage);
			$export = $this->data->export((string) $form->getValues()['customer'], $this->operatorId());
			$response = $this->getHttpResponse();
			$response->setContentType('application/json', 'utf-8');
			$response->setHeader('Content-Disposition', sprintf('attachment; filename="customer-data-%s.json"', gmdate('Ymd-His')));
			$this->sendResponse(new TextResponse(Json::encode($export, pretty: true)));
		};

		return $form;
	}


	protected function createComponentDeleteForm(): Form
	{
		$form = $this->forms->create();
		$form->addHidden('customer')
			->addRule(fn($control): bool => CustomerData::isValidId((string) $control->getValue()), 'privacy.error.id');
		$this->customerField($form, 'confirm')
			->setOption('description', 'privacy.delete-confirm-note')
			->addRule(Form::Equal, 'privacy.error.confirm', $form['customer']);
		$form->addSubmit('delete', 'privacy.delete');
		$form->onSuccess[] = function (Form $form): void {
			$this->requireAllowed(Authorization::Privacy, Authorization::Manage);
			$deleted = $this->data->delete((string) $form->getValues()['customer'], $this->operatorId());
			$this->flashMessage($this->translator->translate('privacy.deleted', 1, $deleted), 'success');
			$this->redirect('this');
		};

		return $form;
	}


	private function customerField(Form $form, string $name): \Nette\Forms\Controls\TextInput
	{
		return $form->addText($name, "privacy.$name")
			->setRequired('form.error.required')
			->setMaxLength(110)
			->setHtmlAttribute('autocomplete', 'off')
			->setHtmlAttribute('spellcheck', 'false')
			->addRule(fn($control): bool => CustomerData::isValidId((string) $control->getValue()), 'privacy.error.id');
	}
}
