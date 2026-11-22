<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Alarms;

use App\Monitoring\AlarmKind;
use App\Monitoring\AlarmThresholds;
use App\Operator\Authorization;
use App\Presentation\Admin\AdminPresenter;
use Nette\Application\UI\Form;


/**
 * Alarm thresholds (admin only). An empty value turns the alarm off; every change is audited.
 */
final class AlarmsPresenter extends AdminPresenter
{
	public function __construct(
		private readonly AlarmThresholds $thresholds,
	) {
		parent::__construct();
	}


	public function actionDefault(): void
	{
		$this->requireAllowed(Authorization::Alarms, Authorization::Manage);
	}


	protected function createComponentAlarmsForm(): Form
	{
		$form = $this->forms->create();
		$thresholds = $this->thresholds->all();
		foreach (AlarmKind::cases() as $kind) {
			$form->addInteger($kind->value, "alarms.kind.$kind->value")
				->setNullable()
				->setHtmlAttribute('min', 1)
				->setHtmlAttribute('max', $kind->max())
				->setOption('description', "alarms.kind.$kind->value-note")
				->setDefaultValue($thresholds[$kind->value])
				->addCondition(Form::Filled)
					->addRule(Form::Range, 'alarms.error.range', [1, $kind->max()]);
		}
		$form->addSubmit('send', 'alarms.form.save');

		$form->onSuccess[] = function (Form $form): void {
			$this->requireAllowed(Authorization::Alarms, Authorization::Manage);
			$values = $form->getValues();
			$thresholds = [];
			foreach (AlarmKind::cases() as $kind) {
				$value = $values[$kind->value];
				$thresholds[$kind->value] = is_int($value) ? $value : null;
			}
			$this->thresholds->save($thresholds, $this->operatorId());
			$this->flashMessage('alarms.saved', 'success');
			$this->redirect('this');
		};

		return $form;
	}
}
