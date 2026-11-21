<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Retention;

use App\Operator\Authorization;
use App\Presentation\Admin\AdminPresenter;
use App\Retention\Retention;
use App\Retention\RetentionData;
use Nette\Application\UI\Form;


/**
 * Retention periods of stored data (admin only). An empty period keeps the data; every change is
 * audited and applied by the worker within an hour.
 */
final class RetentionPresenter extends AdminPresenter
{
	public function __construct(
		private readonly Retention $retention,
	) {
		parent::__construct();
	}


	public function actionDefault(): void
	{
		$this->requireAllowed(Authorization::Retention, Authorization::Manage);
	}


	protected function createComponentRetentionForm(): Form
	{
		$form = $this->forms->create();
		$periods = $this->retention->periods();
		foreach (RetentionData::cases() as $data) {
			$form->addInteger($data->value, "retention.data.$data->value")
				->setNullable()
				->setHtmlAttribute('min', 1)
				->setHtmlAttribute('max', Retention::MaxDays)
				->setOption('description', "retention.data.$data->value-note")
				->setDefaultValue($periods[$data->value])
				->addCondition(Form::Filled)
					->addRule(Form::Range, 'retention.error.range', [1, Retention::MaxDays]);
		}
		$form->addSubmit('send', 'retention.form.save');

		$form->onSuccess[] = function (Form $form): void {
			$this->requireAllowed(Authorization::Retention, Authorization::Manage);
			$values = $form->getValues();
			$periods = [];
			foreach (RetentionData::cases() as $data) {
				$days = $values[$data->value];
				$periods[$data->value] = is_int($days) ? $days : null;
			}
			$this->retention->save($periods, $this->operatorId());
			$this->flashMessage('retention.saved', 'success');
			$this->redirect('this');
		};

		return $form;
	}
}
