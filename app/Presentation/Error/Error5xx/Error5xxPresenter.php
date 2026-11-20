<?php

declare(strict_types=1);

namespace App\Presentation\Error\Error5xx;

use Nette\Application\Attributes\Requires;
use Nette\Application\IPresenter;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Application\Responses\CallbackResponse;
use Nette\Http\IRequest;
use Nette\Http\IResponse;
use Tracy\ILogger;


/**
 * Logs the exception and renders a static page without touching services that may be broken.
 */
#[Requires(forward: true)]
final class Error5xxPresenter implements IPresenter
{
	public function __construct(
		private readonly ILogger $logger,
	) {
	}


	public function run(Request $request): Response
	{
		$this->logger->log($request->getParameter('exception'), ILogger::EXCEPTION);

		return new CallbackResponse(function (IRequest $httpRequest, IResponse $httpResponse): void {
			if (preg_match('#^text/html(?:;|$)#', (string) $httpResponse->getHeader('Content-Type')) === 1) {
				require __DIR__ . '/500.phtml';
			}
		});
	}
}
