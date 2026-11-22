<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Translation\Translator;
use Nette\Application\Attributes\Persistent;
use Nette\Application\UI\Presenter;
use Nette\Bridges\ApplicationLatte\DefaultTemplate;


/**
 * Base of all HTML presenters: UI language from the URL, the translator for templates and
 * forms, and the display time zone (the application and the database work in UTC).
 *
 * @property-read DefaultTemplate $template
 */
abstract class BasePresenter extends Presenter
{
	#[Persistent]
	public string $lang = '';

	/** Set by the DI decorator from version.txt. */
	public string $appVersion;

	/** @var list<string> set by the DI decorator, the first one is the default */
	public array $languages;

	/** Set by the DI decorator (parameter `timezone`). */
	public string $timezone;

	/** Error pages are forwarded requests without links to themselves. */
	protected bool $languageSwitch = true;

	protected Translator $translator;


	public function injectTranslator(Translator $translator): void
	{
		$this->translator = $translator;
	}


	protected function startup(): void
	{
		parent::startup();
		// Forms (CSRF token), flash messages and the sign-in need the session; it cannot start
		// later while the template is already being sent.
		$this->getSession()->start();
		if ($this->lang !== '') {
			$this->translator->setLanguage($this->lang);
		}
	}


	protected function beforeRender(): void
	{
		parent::beforeRender();
		$this->template->setTranslator($this->translator);
		// |number groups digits by the UI language, like the dashboard charts (Intl in the browser).
		$this->template->getLatte()->setLocale($this->translator->getLanguage());
		$this->template->addFilter('localTime', $this->localTime(...));
		$this->template->setParameters([
			'lang' => $this->translator->getLanguage(),
			'languages' => $this->languages,
			'languageSwitch' => $this->languageSwitch,
			'appVersion' => $this->appVersion,
			'icons' => $this->template->basePath . '/assets/admin/icons.svg?v=' . $this->appVersion,
		]);
	}


	private function localTime(?\DateTimeInterface $time): string
	{
		if ($time === null) {
			return '';
		}

		return \DateTimeImmutable::createFromInterface($time)
			->setTimezone(new \DateTimeZone($this->timezone))
			->format($this->translator->getLanguage() === 'cs' ? 'j. n. Y H:i:s' : 'Y-m-d H:i:s');
	}
}
