<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Policy;

use App\Moderation\AbuseRule;
use App\Moderation\AbuseType;
use App\Moderation\Action;
use App\Moderation\AutomationSwitch;
use App\Moderation\Category;
use App\Moderation\InvalidPolicy;
use App\Moderation\Mode;
use App\Moderation\Policies;
use App\Moderation\PolicySettings;
use App\Moderation\Strictness;
use App\Operator\Authorization;
use App\Presentation\Admin\AdminPresenter;
use Nette\Application\UI\Form;


/**
 * Moderation policy (mode, thresholds, allowed automatic actions, content rules, strictness level)
 * and the global panic switch.
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
			'presets' => Strictness::presets(),
			'abuseTypes' => AbuseType::ruled(),
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
			->setOption('description', 'policy.form.autoReplyCategories-note')
			->setDefaultValue(array_map(fn(Category $category) => $category->value, $settings->autoReplyCategories));
		$form->addInteger('autoRepliesPerConversation', 'policy.form.autoRepliesPerConversation')
			->setRequired('form.error.required')
			->addRule(Form::Range, 'policy.error.auto-replies-conversation', [1, PolicySettings::ConversationRepliesLimit])
			->setDefaultValue($settings->autoRepliesPerConversation);
		$form->addInteger('autoRepliesPerDay', 'policy.form.autoRepliesPerDay')
			->setRequired('form.error.required')
			->addRule(Form::Range, 'policy.error.auto-replies-day', [1, PolicySettings::DailyRepliesLimit])
			->setOption('description', 'policy.form.autoRepliesPerDay-note')
			->setDefaultValue($settings->autoRepliesPerDay);
		$form->addText('languages', 'policy.form.languages')
			->setRequired('form.error.required')
			->addRule(Form::Pattern, 'policy.error.languages', '[a-z]{2}( *, *[a-z]{2})*')
			->setDefaultValue(implode(', ', $settings->languages));
		$form->addSelect('otherLanguage', 'policy.form.otherLanguage', [
			Action::Handoff->value => 'action.handoff',
			Action::Review->value => 'action.review',
		])->setDefaultValue($settings->otherLanguage->value);
		$form->addTextArea('blockedDomains', 'policy.form.blockedDomains')
			->setHtmlAttribute('rows', 5)
			->setOption('description', 'policy.form.blockedDomains-note')
			->setDefaultValue(implode("\n", $settings->blockedDomains));
		$form->addInteger('maxLinks', 'policy.form.maxLinks')
			->setNullable()
			->setOption('description', 'policy.form.maxLinks-note')
			->setDefaultValue($settings->maxLinks)
			->addCondition(Form::Filled)
				->addRule(Form::Range, 'policy.error.max-links', [0, PolicySettings::MaxLinksLimit]);
		$form->addInteger('maxRepeats', 'policy.form.maxRepeats')
			->setNullable()
			->setOption('description', 'policy.form.maxRepeats-note')
			->setDefaultValue($settings->maxRepeats)
			->addCondition(Form::Filled)
				->addRule(Form::Range, 'policy.error.max-repeats', [0, PolicySettings::MaxRepeatsLimit]);
		$form->addInteger('repeatWindowHours', 'policy.form.repeatWindowHours')
			->setRequired('form.error.required')
			->addRule(Form::Range, 'policy.error.repeat-window', [1, PolicySettings::RepeatWindowLimit])
			->setDefaultValue($settings->repeatWindowHours);
		$form->addSubmit('send', 'policy.form.save');

		$form->onSuccess[] = function (Form $form) use ($settings): void {
			$this->requireAllowed(Authorization::Policy, Authorization::Manage);
			$values = $form->getValues();
			$categories = is_array($values['autoReplyCategories']) ? $values['autoReplyCategories'] : [];
			try {
				$settings = new PolicySettings(
					hideMinConfidence: (float) $values['hideMinConfidence'],
					hideMinSpamProbability: $settings->hideMinSpamProbability,
					replyMinConfidence: (float) $values['replyMinConfidence'],
					autoHide: (bool) $values['autoHide'],
					autoReplyCategories: array_map(fn($value) => Category::from((string) $value), array_values($categories)),
					languages: array_values(array_unique(array_map('trim', explode(',', (string) $values['languages'])))),
					otherLanguage: Action::from((string) $values['otherLanguage']),
					blockedDomains: self::domains((string) $values['blockedDomains']),
					maxLinks: is_int($values['maxLinks']) ? $values['maxLinks'] : null,
					strictness: $settings->strictness,
					abuseRules: $settings->abuseRules,
					mixedHideMin: $settings->mixedHideMin,
					maxRepeats: is_int($values['maxRepeats']) ? $values['maxRepeats'] : null,
					repeatWindowHours: (int) $values['repeatWindowHours'],
					autoRepliesPerConversation: (int) $values['autoRepliesPerConversation'],
					autoRepliesPerDay: (int) $values['autoRepliesPerDay'],
				);
			} catch (InvalidPolicy $e) {
				$form->addError($e->getMessage());

				return;
			}

			$this->save(Mode::from((string) $values['mode']), $settings);
		};

		return $form;
	}


	/**
	 * Strictness level: a preset copies its thresholds into the new policy version; the custom
	 * level ("danger zone") takes the thresholds from the form.
	 */
	protected function createComponentStrictnessForm(): Form
	{
		$policy = $this->policies->active();
		$settings = $policy->settings;

		$form = $this->forms->create();
		$levels = [];
		foreach (Strictness::cases() as $strictness) {
			$levels[$strictness->value] = "policy.strictness.$strictness->value";
		}
		$strictness = $form->addRadioList('strictness', 'policy.strictness.title', $levels)
			->setRequired('form.error.required')
			->setDefaultValue($settings->strictness->value);

		$threshold = function (string $name, ?float $value, bool $required) use ($form, $strictness): void {
			$input = $form->addFloat($name)
				->setNullable()
				->setHtmlAttribute('step', '0.01')
				->setDefaultValue($value);
			// The thresholds belong to the custom level only; a preset ignores them.
			$custom = $input->addConditionOn($strictness, Form::Equal, Strictness::Custom->value);
			if ($required) {
				$custom->setRequired('form.error.required');
			}
			$custom->addCondition(Form::Filled)
				->addRule(Form::Range, 'policy.error.threshold', [0.5, 1.0]);
		};
		$threshold('spamHideMin', $settings->hideMinSpamProbability, true);
		foreach (AbuseType::ruled() as $type) {
			$rule = $settings->abuseRule($type);
			$threshold("{$type->value}_hide", $rule->hideMin, false);
			$threshold("{$type->value}_review", $rule->reviewMin, false);
		}
		$threshold('mixedHideMin', $settings->mixedHideMin, false);
		$form->addSubmit('send', 'policy.strictness.save');

		$form->onSuccess[] = function (Form $form) use ($policy): void {
			$this->requireAllowed(Authorization::Policy, Authorization::Manage);
			$values = $form->getValues();
			$strictness = Strictness::from((string) $values['strictness']);
			$float = static fn(string $name): ?float => is_float($values[$name]) || is_int($values[$name]) ? (float) $values[$name] : null;
			try {
				if ($strictness === Strictness::Custom) {
					$rules = [];
					foreach (AbuseType::ruled() as $type) {
						$rules[$type->value] = new AbuseRule($float("{$type->value}_hide"), $float("{$type->value}_review"));
					}
					$settings = $policy->settings->withStrictness($strictness, (float) $float('spamHideMin'), $rules, $float('mixedHideMin'));
				} else {
					$settings = $policy->settings->withStrictness($strictness, $strictness->spamHideMin(), $strictness->abuseRules(), null);
				}
			} catch (InvalidPolicy $e) {
				$form->addError($e->getMessage());

				return;
			}

			$this->save($policy->mode, $settings);
		};

		return $form;
	}


	private function save(Mode $mode, PolicySettings $settings): never
	{
		$policy = $this->policies->save($mode, $settings, $this->operatorId());
		$this->flashMessage($this->translator->translate('policy.saved', 1, [
			'version' => $policy->version,
		]), 'success');
		$this->redirect('this');
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


	/**
	 * One domain per line (commas and spaces also separate), case-insensitive, without duplicates.
	 *
	 * @return list<string>
	 */
	private static function domains(string $input): array
	{
		$domains = preg_split('~[\s,]+~', mb_strtolower($input), flags: PREG_SPLIT_NO_EMPTY) ?: [];

		return array_values(array_unique($domains));
	}
}
