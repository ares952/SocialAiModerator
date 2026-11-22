<?php

declare(strict_types=1);

namespace App\Meta;


/**
 * Failed Meta request. `uncertain`: a write may have been applied (timeout after sending, 5xx),
 * so it must not be repeated before a reconciliation. `transient`: Meta surely did not apply it
 * and a later retry may succeed (rate limits, connection refused). Messages never contain URLs,
 * tokens or customer content.
 */
final class MetaFailure extends \RuntimeException
{
	/** Graph error codes of rate limits (platform 4, 17, 32, 613; business use case 80001–80008). */
	private const RateLimitCodes = [
		4,
		17,
		32,
		613,
		80001,
		80002,
		80005,
		80006,
		80008,
	];

	/** Graph error codes Meta documents as temporary (unknown error, service unavailable). */
	private const TemporaryCodes = [
		1,
		2,
	];


	public function __construct(
		string $message,
		public readonly bool $uncertain,
		public readonly bool $transient,
		public readonly ?int $httpStatus = null,
		public readonly ?int $errorCode = null,
		?\Throwable $previous = null,
		public readonly ?int $errorSubcode = null,
	) {
		parent::__construct($message, 0, $previous);
	}


	/**
	 * Classifies a failed Graph API response. A write that may have reached Meta (no response,
	 * 5xx) is uncertain; for a read nothing is uncertain.
	 *
	 * @param array<mixed>|null $body decoded response body
	 */
	public static function fromGraph(string $operation, int $httpStatus, ?array $body, bool $write): self
	{
		$error = is_array($body['error'] ?? null) ? $body['error'] : [];
		$code = is_int($error['code'] ?? null) ? $error['code'] : null;
		$subcode = is_int($error['error_subcode'] ?? null) ? $error['error_subcode'] : null;
		$noAnswer = $httpStatus === 0 || $httpStatus >= 500;
		$transient = $httpStatus === 429
			|| in_array($code, self::RateLimitCodes, true)
			|| in_array($code, self::TemporaryCodes, true)
			|| ($noAnswer && !$write);

		return new self(
			sprintf(
				'Graph %s failed with HTTP %d%s.',
				$operation,
				$httpStatus,
				$code === null ? '' : " (code $code" . ($subcode === null ? '' : "/$subcode") . ')',
			),
			uncertain: $write && $noAnswer,
			transient: $transient,
			httpStatus: $httpStatus,
			errorCode: $code,
			errorSubcode: $subcode,
		);
	}


	/**
	 * The token is invalid or expired (code 190, or 102 = session invalid); a new one is needed.
	 */
	public function invalidToken(): bool
	{
		return in_array($this->errorCode, [102, 190], true);
	}
}
