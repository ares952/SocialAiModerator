<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Accounts;

use App\Account\Accounts;
use App\Account\AccountStatus;
use App\Account\InvalidAccount;
use App\Meta\Platform;
use App\Moderation\AutomationSwitch;
use App\Operator\Authorization;
use App\Presentation\Admin\AdminPresenter;
use Nette\Application\UI\Form;
use Nette\Database\Row;


/**
 * Connected social accounts: everyone reads, an admin adds, (de)activates and pauses them.
 */
final class AccountsPresenter extends AdminPresenter
{
	private ?Row $account = null;


	public function __construct(
		private readonly Accounts $accounts,
		private readonly AutomationSwitch $automation,
	) {
		parent::__construct();
	}


	public function actionDefault(): void
	{
		$this->requireAllowed(Authorization::Accounts, Authorization::View);
		$this->template->setParameters([
			'accounts' => $this->accounts->all(),
			'canManage' => $this->getUser()->isAllowed(Authorization::Accounts, Authorization::Manage),
		]);
	}


	public function actionAdd(): void
	{
		$this->requireAllowed(Authorization::Accounts, Authorization::Manage);
	}


	public function actionEdit(int $id): void
	{
		$this->requireAllowed(Authorization::Accounts, Authorization::Manage);
		$this->account = $this->accounts->find($id) ?? $this->error();
		$form = $this->getComponent('accountForm');
		assert($form instanceof Form);
		$form->setDefaults([
			'platform' => $this->account->platform,
			'externalId' => substr($this->account->external_id, 3),
			'name' => $this->account->name,
			'status' => $this->account->status,
		]);
		$this->template->setParameters([
			'account' => $this->account,
		]);
	}


	public function handlePause(int $account): void
	{
		$this->requireAllowed(Authorization::Accounts, Authorization::Manage);
		$this->automation->pause($account, null, $this->operatorId());
		$this->flashMessage('automation.paused', 'warning');
		$this->redirect('default');
	}


	public function handleResume(int $account): void
	{
		$this->requireAllowed(Authorization::Accounts, Authorization::Manage);
		$this->automation->resume($account, $this->operatorId());
		$this->flashMessage('automation.resumed', 'success');
		$this->redirect('default');
	}


	protected function createComponentAccountForm(): Form
	{
		$form = $this->forms->create();
		$platforms = [];
		foreach (Platform::cases() as $platform) {
			$platforms[$platform->value] = "platform.$platform->value";
		}
		$platform = $form->addSelect('platform', 'account.form.platform', $platforms);
		$externalId = $form->addText('externalId', 'account.form.external-id')
			->setRequired('form.error.required')
			->addRule(Form::Pattern, 'account.error.external-id', '\d{1,64}')
			->setOption('description', 'account.form.external-id-note');
		$form->addText('name', 'account.form.name')
			->setRequired('form.error.required')
			->setMaxLength(255);
		$statuses = [];
		foreach (AccountStatus::cases() as $status) {
			$statuses[$status->value] = "account.status.$status->value";
		}
		$form->addSelect('status', 'account.form.status', $statuses)
			->setOption('description', 'account.form.status-note');
		$form->addSubmit('send', 'account.form.save');

		// The platform and the ID identify the account in webhooks; they never change.
		if ($this->getAction() === 'edit') {
			$platform->setDisabled();
			$externalId->setDisabled();
		}

		$form->onSuccess[] = function (Form $form): void {
			$this->requireAllowed(Authorization::Accounts, Authorization::Manage);
			$values = $form->getValues();
			$status = AccountStatus::from((string) $values['status']);
			try {
				if ($this->account === null) {
					$this->accounts->create(
						Platform::from((string) $values['platform']),
						(string) $values['externalId'],
						(string) $values['name'],
						$status,
						$this->operatorId(),
					);
				} else {
					$this->accounts->update((int) $this->account->id, (string) $values['name'], $status, $this->operatorId());
				}
			} catch (InvalidAccount $e) {
				$form->addError($e->getMessage());

				return;
			}

			$this->flashMessage('account.saved', 'success');
			$this->redirect('default');
		};

		return $form;
	}
}
