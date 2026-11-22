<?php

declare(strict_types=1);

namespace App\Meta;


/**
 * What Meta says about an access token (GET /debug_token). Expiry times are UTC; null means the
 * token or its data access does not expire.
 */
final readonly class TokenInfo
{
	/**
	 * @param list<string> $scopes granted permissions
	 * @param array<string, list<string>> $targets permission => IDs it is restricted to (granular scopes)
	 */
	public function __construct(
		public bool $valid,
		public ?string $appId,
		public ?string $type,
		public ?\DateTimeImmutable $expiresAt,
		public ?\DateTimeImmutable $dataAccessExpiresAt,
		public array $scopes,
		public array $targets,
		public ?int $errorCode = null,
	) {
	}


	/**
	 * @param array<mixed> $response body of GET /debug_token
	 * @throws MetaFailure the response has no `data` object
	 */
	public static function fromApi(array $response): self
	{
		$data = $response['data'] ?? null;
		if (!is_array($data)) {
			throw new MetaFailure('Graph GET /debug_token returned no data.', uncertain: false, transient: false);
		}

		$time = static function (mixed $value): ?\DateTimeImmutable {
			// 0 = never expires
			return is_int($value) && $value > 0 ? (new \DateTimeImmutable("@$value"))->setTimezone(new \DateTimeZone('UTC')) : null;
		};
		$targets = [];
		foreach (is_array($data['granular_scopes'] ?? null) ? $data['granular_scopes'] : [] as $granular) {
			if (is_array($granular) && is_string($granular['scope'] ?? null) && is_array($granular['target_ids'] ?? null)) {
				$targets[$granular['scope']] = array_values(array_map(strval(...), $granular['target_ids']));
			}
		}

		return new self(
			valid: ($data['is_valid'] ?? false) === true,
			appId: isset($data['app_id']) ? (string) $data['app_id'] : null,
			type: is_string($data['type'] ?? null) ? $data['type'] : null,
			expiresAt: $time($data['expires_at'] ?? null),
			dataAccessExpiresAt: $time($data['data_access_expires_at'] ?? null),
			scopes: array_values(array_filter(
				is_array($data['scopes'] ?? null) ? $data['scopes'] : [],
				is_string(...),
			)),
			targets: $targets,
			errorCode: is_int($data['error']['code'] ?? null) ? $data['error']['code'] : null,
		);
	}
}
