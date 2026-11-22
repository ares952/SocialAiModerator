<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Accounts;

use App\Account\Accounts;
use App\Account\AccountStatus;
use App\Account\Credentials;
use App\Account\InvalidAccount;
use App\Account\InvalidCredential;
use App\Account\TokenType;
use App\Meta\Platform;
use App\Monitoring\MetricRange;
use App\Monitoring\MetricSeries;
use App\Monitoring\Timeline;
use App\Moderation\AutomationSwitch;
use App\Operator\Authorization;
use App\Presentation\Admin\AdminPresenter;
use App\Presentation\Admin\ChartExport;
use App\Presentation\Admin\Charts;
use Nette\Application\UI\Form;
use Nette\Database\Row;


/**
 * Connected social accounts: everyone reads, an admin adds, (de)activates and pauses them and
 * stores their Meta access tokens. A stored token is never shown again. The overview charts the
 * received events per account over the last 30 days.
 *
 * @phpstan-import-type Chart from Charts
 */
final class AccountsPresenter extends AdminPresenter
{
	use ChartExport;

	private ?Row $account = null;


	public function __construct(
		private readonly Accounts $accounts,
		private readonly AutomationSwitch $automation,
		private readonly Credentials $credentials,
		private readonly MetricSeries $series,
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


	public function renderDefault(): void
	{
		$this->template->setParameters([
			'charts' => $this->exportCharts(),
		]);
	}


	/**
	 * @return array{events: Chart}
	 */
	protected function exportCharts(): array
	{
		$timeline = Timeline::create(MetricRange::Month, new \DateTimeImmutable, new \DateTimeZone($this->timezone));

		return [
			'events' => $this->charts->chart('bar', $this->charts->labels($timeline), $this->charts->named($this->series->accounts($timeline)), [
				'stacked' => true,
			]),
		];
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


	public function actionToken(int $id): void
	{
		$this->requireAllowed(Authorization::Accounts, Authorization::Manage);
		$this->account = $this->accounts->find($id) ?? $this->error();
		$credential = $this->credentials->find($id);
		if ($credential !== null) {
			$form = $this->getComponent('tokenForm');
			assert($form instanceof Form);
			$form->setDefaults([
				'type' => $credential->token_type,
				'scopes' => str_replace(',', ', ', $credential->scopes),
				'neverExpires' => $credential->expires_at === null,
				'expires' => $this->localInput($credential->expires_at),
				'dataAccessExpires' => $this->localInput($credential->data_access_expires_at),
			]);
		}

		$this->template->setParameters([
			'account' => $this->account,
			'credential' => $credential,
			'timezone' => $this->timezone,
		]);
	}


	public function handlePause(int $account): void
	{
		$this->requireAllowed(Authorization::Accounts, Authorization::Manage);
		$this->accounts->find($account) ?? $this->error();
		$this->automation->pause($account, null, $this->operatorId());
		$this->flashMessage('automation.paused', 'warning');
		$this->redirect('default');
	}


	public function handleResume(int $account): void
	{
		$this->requireAllowed(Authorization::Accounts, Authorization::Manage);
		$this->accounts->find($account) ?? $this->error();
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


	protected function createComponentTokenForm(): Form
	{
		$form = $this->forms->create();
		$form->addPassword('accessToken', 'account.token.form.token')
			->setRequired('form.error.required')
			->addRule(Form::Pattern, 'account.token.error.token', '[\x21-\x7E]{1,2000}')
			->setHtmlAttribute('autocomplete', 'off')
			->setOption('description', 'account.token.form.token-note');
		$types = [];
		foreach (TokenType::cases() as $type) {
			$types[$type->value] = "account.token.type.$type->value";
		}
		$form->addSelect('type', 'account.token.form.type', $types);
		$form->addText('scopes', 'account.token.form.scopes')
			->setRequired('form.error.required')
			->addRule(Form::Pattern, 'account.token.error.scopes', '\s*[a-z][a-z_]*(\s*,\s*[a-z][a-z_]*)*\s*')
			->setOption('description', 'account.token.form.scopes-note');
		$never = $form->addCheckbox('neverExpires', 'account.token.never')
			->setDefaultValue(true);
		$form->addDateTime('expires', 'account.token.form.expires')
			->setOption('description', 'account.token.form.time-note')
			->addConditionOn($never, Form::Equal, false)
				->setRequired('form.error.required');
		$form->addDateTime('dataAccessExpires', 'account.token.form.data-access')
			->setOption('description', 'account.token.form.time-note');
		$form->addSubmit('send', 'account.token.form.save');

		$form->onSuccess[] = function (Form $form): void {
			$this->requireAllowed(Authorization::Accounts, Authorization::Manage);
			$account = $this->account ?? $this->error();
			$values = $form->getValues();
			try {
				$this->credentials->replace(
					(int) $account->id,
					trim((string) $values['accessToken']),
					TokenType::from((string) $values['type']),
					array_values(array_filter(array_map(trim(...), explode(',', (string) $values['scopes'])))),
					$values['neverExpires'] ? null : $this->fromLocal($values['expires']),
					$this->fromLocal($values['dataAccessExpires']),
					$this->operatorId(),
				);
			} catch (InvalidCredential) {
				$form->addError('account.token.error.invalid');

				return;
			}

			$this->flashMessage('account.token.saved', 'success');
			$this->redirect('default');
		};

		return $form;
	}


	/**
	 * A stored UTC time as the value of a date-time input in the administration's time zone.
	 */
	private function localInput(?\DateTimeInterface $time): ?\DateTimeImmutable
	{
		if ($time === null) {
			return null;
		}

		$local = \DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone($this->timezone));

		return new \DateTimeImmutable($local->format('Y-m-d H:i:s'));
	}


	/**
	 * A date-time input (wall clock in the administration's time zone) as an absolute time.
	 */
	private function fromLocal(mixed $value): ?\DateTimeImmutable
	{
		return $value instanceof \DateTimeInterface
			? new \DateTimeImmutable($value->format('Y-m-d H:i:s'), new \DateTimeZone($this->timezone))
			: null;
	}
}
