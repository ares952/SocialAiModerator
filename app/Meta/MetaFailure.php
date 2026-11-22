<?php

declare(strict_types=1);

namespace App\Meta;


/**
 * Failed Meta write. `uncertain`: the request may have been applied (timeout after sending, 5xx),
 * so it must not be repeated before a reconciliation. `transient`: Meta surely did not apply it
 * and a later retry may succeed (429, connection refused). Messages never contain tokens or
 * customer content.
 */
final class MetaFailure extends \RuntimeException
{
	public function __construct(
		string $message,
		public readonly bool $uncertain,
		public readonly bool $transient,
		public readonly ?int $httpStatus = null,
		public readonly ?int $errorCode = null,
		?\Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}
}
