<?php

declare(strict_types=1);

namespace App\Presentation\Health;

use Nette\Application\UI\Presenter;
use Nette\Database\DriverException;
use Nette\Database\Explorer;
use Nette\Http\IResponse;
use Tracy\ILogger;


/**
 * Liveness and readiness probes. Responses never contain internal details.
 */
final class HealthPresenter extends Presenter
{
	public function __construct(
		private readonly Explorer $database,
		private readonly ILogger $logger,
	) {
		parent::__construct();
	}


	public function actionLive(): void
	{
		$this->sendJson([
			'status' => 'ok',
		]);
	}


	public function actionReady(): void
	{
		try {
			// The queue lives in the database; a missing table means pending migrations.
			$this->database->query('SELECT 1 FROM queue_jobs LIMIT 1');
		} catch (DriverException $e) {
			$this->logger->log($e, ILogger::EXCEPTION);
			$this->getHttpResponse()->setCode(IResponse::S503_ServiceUnavailable);
			$this->sendJson([
				'status' => 'unavailable',
			]);
		}

		$this->sendJson([
			'status' => 'ok',
		]);
	}
}
