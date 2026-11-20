<?php

declare(strict_types=1);

namespace App\Presentation\Error\Error4xx;

use App\Presentation\BasePresenter;
use Nette\Application\Attributes\Requires;
use Nette\Application\BadRequestException;


#[Requires(methods: '*', forward: true)]
final class Error4xxPresenter extends BasePresenter
{
	public function renderDefault(BadRequestException $exception): void
	{
		$this->template->setParameters([
			'httpCode' => $exception->getHttpCode(),
		]);
	}
}
