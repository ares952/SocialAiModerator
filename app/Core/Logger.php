<?php

declare(strict_types=1);

namespace App\Core;

use Nette\Utils\Json;
use Tracy\ILogger;


/**
 * Structured application log (specification §8.4): one JSON object per line in log/app.log with
 * time (UTC), level, message, correlation_id and context such as event_id, account_id, job_id or
 * action_id. Messages and context never contain customer content, tokens or secrets.
 * Exceptions additionally go to Tracy, which keeps the bluescreen (secrets hidden by keysToHide).
 */
final class Logger
{
	public function __construct(
		private readonly string $file,
		private readonly Correlation $correlation,
		private readonly ILogger $tracy,
	) {
	}


	/**
	 * @param array<string, scalar|null> $context
	 */
	public function info(string $message, array $context = []): void
	{
		$this->write('info', $message, $context);
	}


	/**
	 * @param array<string, scalar|null> $context
	 */
	public function warning(string $message, array $context = []): void
	{
		$this->write('warning', $message, $context);
	}


	/**
	 * @param array<string, scalar|null> $context
	 */
	public function error(string $message, array $context = [], ?\Throwable $exception = null): void
	{
		if ($exception !== null) {
			$context['exception'] = $exception::class;
			$this->tracy->log($exception, ILogger::EXCEPTION);
		}

		$this->write('error', $message, $context);
	}


	/**
	 * @param array<string, scalar|null> $context
	 */
	private function write(string $level, string $message, array $context): void
	{
		$line = Json::encode([
			'time' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
			'level' => $level,
			'message' => $message,
			'correlation_id' => $this->correlation->id(),
		] + $context);

		if (file_put_contents($this->file, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
			throw new \RuntimeException("Cannot write the application log $this->file.");
		}
	}
}
