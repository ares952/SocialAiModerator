<?php

declare(strict_types=1);

namespace App\Presentation\Error\Error4xx;

use App\Presentation\BasePresenter;
use Nette\Application\Attributes\Requires;
use Nette\Application\BadRequestException;


#[Requires(methods: '*', forward: true)]
final class Error4xxPresenter extends BasePresenter
{
	protected bool $languageSwitch = false;


	public function renderDefault(BadRequestException $exception): void
	{
		$code = $exception->getHttpCode();
		$this->template->setParameters([
			'httpCode' => $code,
			'messageKey' => $code === 403 ? 'system.error.forbidden' : 'system.error.not-found',
		]);
	}
}
