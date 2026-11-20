<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Policy;

use App\Moderation\Action;
use App\Moderation\AutomationSwitch;
use App\Moderation\Category;
use App\Moderation\InvalidPolicy;
use App\Moderation\Mode;
use App\Moderation\Policies;
use App\Moderation\PolicySettings;
use App\Operator\Authorization;
use App\Presentation\Admin\AdminPresenter;
use Nette\Application\UI\Form;


/**
 * Moderation policy (mode, thresholds, allowed automatic actions) and the global panic switch.
 * Everyone may read it; only an admin changes it. Every save is a new audited version.
 */
final class PolicyPresenter extends AdminPresenter
{
	public function __construct(
		private readonly Policies $policies,
		private readonly AutomationSwitch $automation,
	) {
		parent::__construct();
	}


	public function actionDefault(): void
	{
		$this->requireAllowed(Authorization::Policy, Authorization::View);
	}


	public function renderDefault(): void
	{
		$this->template->setParameters([
			'policy' => $this->policies->active(),
			'history' => $this->policies->history(),
			'pause' => $this->automation->globalPause(),
			'canManage' => $this->getUser()->isAllowed(Authorization::Policy, Authorization::Manage),
		]);
	}


	protected function createComponentPolicyForm(): Form
	{
		$policy = $this->policies->active();
		$settings = $policy->settings;

		$form = $this->forms->create();
		$modes = [];
		foreach (Mode::cases() as $mode) {
			$modes[$mode->value] = "mode.$mode->value";
		}
		$form->addSelect('mode', 'policy.form.mode', $modes)
			->setDefaultValue($policy->mode->value)
			->setOption('description', 'policy.form.mode-note');
		foreach ([
			'hideMinConfidence' => $settings->hideMinConfidence,
			'hideMinSpamProbability' => $settings->hideMinSpamProbability,
			'replyMinConfidence' => $settings->replyMinConfidence,
		] as $name => $value) {
			$form->addFloat($name, "policy.form.$name")
				->setRequired('form.error.required')
				->addRule(Form::Range, 'policy.error.threshold', [0.5, 1.0])
				->setHtmlAttribute('step', '0.01')
				->setDefaultValue($value);
		}
		$form->addCheckbox('autoHide', 'policy.form.autoHide')
			->setDefaultValue($settings->autoHide);

		$categories = [];
		foreach (Category::cases() as $category) {
			if (!$category->requiresHandoff()) {
				$categories[$category->value] = "category.$category->value";
			}
		}
		$form->addCheckboxList('autoReplyCategories', 'policy.form.autoReplyCategories', $categories)
			->setDefaultValue(array_map(fn(Category $category) => $category->value, $settings->autoReplyCategories));
		$form->addText('languages', 'policy.form.languages')
			->setRequired('form.error.required')
			->addRule(Form::Pattern, 'policy.error.languages', '[a-z]{2}( *, *[a-z]{2})*')
			->setDefaultValue(implode(', ', $settings->languages));
		$form->addSelect('otherLanguage', 'policy.form.otherLanguage', [
			Action::Handoff->value => 'action.handoff',
			Action::Review->value => 'action.review',
		])->setDefaultValue($settings->otherLanguage->value);
		$form->addSubmit('send', 'policy.form.save');

		$form->onSuccess[] = function (Form $form): void {
			$this->requireAllowed(Authorization::Policy, Authorization::Manage);
			$values = $form->getValues();
			$categories = is_array($values['autoReplyCategories']) ? $values['autoReplyCategories'] : [];
			try {
				$settings = new PolicySettings(
					hideMinConfidence: (float) $values['hideMinConfidence'],
					hideMinSpamProbability: (float) $values['hideMinSpamProbability'],
					replyMinConfidence: (float) $values['replyMinConfidence'],
					autoHide: (bool) $values['autoHide'],
					autoReplyCategories: array_map(fn($value) => Category::from((string) $value), array_values($categories)),
					languages: array_values(array_unique(array_map('trim', explode(',', (string) $values['languages'])))),
					otherLanguage: Action::from((string) $values['otherLanguage']),
				);
			} catch (InvalidPolicy $e) {
				$form->addError($e->getMessage());

				return;
			}

			$policy = $this->policies->save(Mode::from((string) $values['mode']), $settings, $this->operatorId());
			$this->flashMessage($this->translator->translate('policy.saved', 1, [
				'version' => $policy->version,
			]), 'success');
			$this->redirect('this');
		};

		return $form;
	}


	protected function createComponentPauseForm(): Form
	{
		$form = $this->forms->create();
		$form->addText('reason', 'automation.form.reason')
			->setMaxLength(255);
		$form->addSubmit('pause', 'automation.form.pause')
			->getControlPrototype()->appendAttribute('class', 'btn-danger');

		$form->onSuccess[] = function (Form $form): void {
			$this->requireAllowed(Authorization::Policy, Authorization::Manage);
			$this->automation->pause(null, (string) $form->getValues()['reason'], $this->operatorId());
			$this->flashMessage('automation.paused', 'warning');
			$this->redirect('this');
		};

		return $form;
	}


	protected function createComponentResumeForm(): Form
	{
		$form = $this->forms->create();
		$form->addSubmit('send', 'automation.form.resume');

		$form->onSuccess[] = function (): void {
			$this->requireAllowed(Authorization::Policy, Authorization::Manage);
			$this->automation->resume(null, $this->operatorId());
			$this->flashMessage('automation.resumed', 'success');
			$this->redirect('this');
		};

		return $form;
	}
}
