<?php

declare(strict_types=1);

namespace App\Ai;


/**
 * Failed OpenAI call. Transient failures (timeout, 429, 5xx) are worth a retry with backoff;
 * the others are not. Messages never contain customer content or the API key.
 */
final class OpenAiFailure extends \RuntimeException
{
	public function __construct(
		string $message,
		public readonly bool $transient,
		?\Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}
}
