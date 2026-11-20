<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Translation\Translator;
use Nette\Application\Attributes\Persistent;
use Nette\Application\UI\Presenter;
use Nette\Bridges\ApplicationLatte\DefaultTemplate;


/**
 * Base of all HTML presenters: UI language from the URL and the translator for templates.
 *
 * @property-read DefaultTemplate $template
 */
abstract class BasePresenter extends Presenter
{
	#[Persistent]
	public string $lang = '';

	/** Set by the DI decorator from version.txt. */
	public string $appVersion;

	private Translator $translator;


	public function injectTranslator(Translator $translator): void
	{
		$this->translator = $translator;
	}


	protected function startup(): void
	{
		parent::startup();
		if ($this->lang !== '') {
			$this->translator->setLanguage($this->lang);
		}
	}


	protected function beforeRender(): void
	{
		parent::beforeRender();
		$this->template->setTranslator($this->translator);
		$this->template->setParameters([
			'lang' => $this->translator->getLanguage(),
			'appVersion' => $this->appVersion,
		]);
	}
}
