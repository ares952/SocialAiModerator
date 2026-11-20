<?php

declare(strict_types=1);

namespace App\Presentation\Api\MetaWebhook;

use App\Core\Logger;
use App\Ingest\EventStore;
use App\Ingest\InvalidWebhookPayload;
use App\Ingest\WebhookBatch;
use App\Meta\WebhookVerifier;
use Nette\Application\IPresenter;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Application\Responses\TextResponse;
use Nette\Http\IRequest;
use Nette\Http\IResponse;


/**
 * Meta webhook endpoint (specification §3.3). GET answers the subscription challenge. POST
 * verifies the signature over the raw body and only stores the events; the worker processes
 * them, so no AI or Meta call runs inside this request.
 */
final class MetaWebhookPresenter implements IPresenter
{
	public function __construct(
		private readonly WebhookVerifier $verifier,
		private readonly EventStore $events,
		private readonly IRequest $httpRequest,
		private readonly IResponse $httpResponse,
		private readonly Logger $logger,
	) {
	}


	public function run(Request $request): Response
	{
		return match ($this->httpRequest->getMethod()) {
			'GET' => $this->subscribe(),
			'POST' => $this->receive(),
			default => $this->methodNotAllowed(),
		};
	}


	private function subscribe(): Response
	{
		// Nette keeps the dots in parameter names (unlike $_GET).
		$mode = $this->httpRequest->getQuery('hub.mode');
		$token = $this->httpRequest->getQuery('hub.verify_token');
		$challenge = $this->httpRequest->getQuery('hub.challenge');

		if (
			!is_string($challenge)
			|| !$this->verifier->isSubscription(
				is_string($mode) ? $mode : null,
				is_string($token) ? $token : null,
			)
		) {
			$this->logger->warning('Meta webhook subscription rejected: invalid verify token');

			return $this->text(IResponse::S403_Forbidden, '');
		}

		return $this->text(IResponse::S200_OK, $challenge);
	}


	private function receive(): Response
	{
		$body = $this->httpRequest->getRawBody() ?? '';
		if (!$this->verifier->isSigned($body, $this->httpRequest->getHeader('X-Hub-Signature-256'))) {
			$this->logger->warning('Meta webhook rejected: invalid signature');

			return $this->text(IResponse::S403_Forbidden, '');
		}

		try {
			$batch = WebhookBatch::parse($body);
		} catch (InvalidWebhookPayload $e) {
			// Only the message: a bluescreen would dump the body with customer content.
			$this->logger->error('Meta webhook rejected: ' . $e->getMessage());

			return $this->text(IResponse::S400_BadRequest, '');
		}

		$this->events->save($batch);
		$this->logger->info('Meta webhook received', [
			'events' => count($batch->events),
		]);

		return $this->text(IResponse::S200_OK, 'EVENT_RECEIVED');
	}


	private function methodNotAllowed(): Response
	{
		$this->httpResponse->setHeader('Allow', 'GET, POST');

		return $this->text(IResponse::S405_MethodNotAllowed, '');
	}


	private function text(int $code, string $text): TextResponse
	{
		$this->httpResponse->setCode($code);
		$this->httpResponse->setContentType('text/plain', 'utf-8');

		return new TextResponse($text);
	}
}
