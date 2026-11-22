<?php

declare(strict_types=1);

namespace App\Meta;

use Nette\Utils\Json;
use Nette\Utils\JsonException;


/**
 * HTTPS transport to graph.facebook.com with the pinned API version (META_GRAPH_API_VERSION,
 * docs/meta-checklist.md). Used by the Graph read client and later by the write client; failures
 * become MetaFailure. The URL carries the access token as Meta documents it, so it is never
 * logged or put into an exception message.
 */
final class GraphApi
{
	private const Host = 'https://graph.facebook.com';


	public function __construct(
		private readonly string $version,
		private readonly int $timeoutSeconds = 15,
	) {
		if (preg_match('~^v\d+\.\d+$~D', $version) !== 1) {
			throw new \LogicException('META_GRAPH_API_VERSION must look like v27.0.');
		}
	}


	/**
	 * @param string $path e.g. /debug_token or /{comment-id}
	 * @param array<string, string> $query parameters besides the access token
	 * @return array<mixed> decoded response
	 * @throws MetaFailure
	 */
	public function get(string $path, array $query, #[\SensitiveParameter] string $accessToken): array
	{
		$curl = curl_init(self::Host . '/' . $this->version . $path . '?' . http_build_query($query + [
			'access_token' => $accessToken,
		]));
		curl_setopt_array($curl, [
			CURLOPT_HTTPGET => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => $this->timeoutSeconds,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_HTTPHEADER => [
				'Accept: application/json',
			],
		]);
		$response = curl_exec($curl);
		$status = is_string($response) ? (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE) : 0;
		$operation = 'GET ' . self::operation($path);

		try {
			$body = is_string($response) ? Json::decode($response, forceArrays: true) : null;
		} catch (JsonException) {
			$body = null;
		}

		if ($status !== 200 || !is_array($body)) {
			throw MetaFailure::fromGraph($operation, $status, is_array($body) ? $body : null, write: false);
		}

		return $body;
	}


	/**
	 * The path for messages: object IDs (which identify customers' content) are replaced.
	 */
	private static function operation(string $path): string
	{
		return preg_replace('~/[0-9_]+~', '/{id}', $path) ?? '/{id}';
	}
}
