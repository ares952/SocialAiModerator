<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Translation\Translator;
use Nette\Application\UI\Form;
use Nette\Forms\Controls;
use Nette\Forms\Rendering\DefaultFormRenderer;


/**
 * Forms of the administration: translated, CSRF-protected and rendered with Bootstrap 5 classes.
 */
final class FormFactory
{
	public function __construct(
		private readonly Translator $translator,
	) {
	}


	public function create(): Form
	{
		$form = new Form;
		$form->setTranslator($this->translator);
		$form->addProtection('form.error.csrf');
		$form->onRender[] = self::bootstrap(...);

		return $form;
	}


	private static function bootstrap(Form $form): void
	{
		$renderer = $form->getRenderer();
		assert($renderer instanceof DefaultFormRenderer);
		$renderer->wrappers['error']['container'] = 'div class="alert alert-danger"';
		$renderer->wrappers['error']['item'] = 'div';
		$renderer->wrappers['controls']['container'] = null;
		$renderer->wrappers['pair']['container'] = 'div class=mb-3';
		$renderer->wrappers['label']['container'] = null;
		$renderer->wrappers['control']['container'] = null;
		$renderer->wrappers['control']['description'] = 'div class=form-text';
		$renderer->wrappers['control']['errorcontainer'] = 'div class=invalid-feedback';
		$renderer->wrappers['control']['.error'] = 'is-invalid';

		foreach ($form->getControls() as $control) {
			match (true) {
				$control instanceof Controls\Button => $control->getControlPrototype()
					->appendAttribute('class', $control->getName() === 'send' ? 'btn btn-primary' : 'btn btn-secondary'),
				$control instanceof Controls\Checkbox => self::checkbox($control),
				$control instanceof Controls\SelectBox => self::field($control, 'form-select'),
				$control instanceof Controls\TextBase => self::field($control, 'form-control'),
				default => null,
			};
		}
	}


	private static function field(Controls\BaseControl $control, string $class): void
	{
		$control->getControlPrototype()->appendAttribute('class', $class);
		$control->getLabelPrototype()->appendAttribute('class', 'form-label');
	}


	private static function checkbox(Controls\Checkbox $control): void
	{
		$control->getControlPrototype()->appendAttribute('class', 'form-check-input');
		$control->getContainerPrototype()->setName('div')->appendAttribute('class', 'form-check');
		$control->getLabelPrototype()->appendAttribute('class', 'form-check-label');
	}
}
