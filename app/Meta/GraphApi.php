<?php

declare(strict_types=1);

namespace App\Meta;

use Nette\Utils\Json;
use Nette\Utils\JsonException;


/**
 * HTTPS transport to graph.facebook.com with the pinned API version (META_GRAPH_API_VERSION,
 * docs/meta-checklist.md) for the read client (GET, token in the query as Meta documents it) and
 * the write client (POST, token in the body). Failures become MetaFailure; URLs, tokens and
 * response bodies never go into messages or logs.
 */
final class GraphApi
{
	private const Host = 'https://graph.facebook.com';


	/**
	 * @param string $appId META_APP_ID; empty = no request leaves (the e2e stack, unconfigured servers)
	 */
	public function __construct(
		private readonly string $version,
		private readonly string $appId,
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
		return $this->request('GET', $path, $query + [
			'access_token' => $accessToken,
		]);
	}


	/**
	 * A write. Parameters and the token travel in the form body, never in the URL. Without any
	 * response after the request was sent the result is uncertain (MetaFailure::$uncertain).
	 *
	 * @param array<string, string> $fields form fields; nested values JSON-encoded as Meta documents
	 * @return array<mixed> decoded response
	 * @throws MetaFailure
	 */
	public function post(string $path, array $fields, #[\SensitiveParameter] string $accessToken): array
	{
		return $this->request('POST', $path, $fields + [
			'access_token' => $accessToken,
		]);
	}


	/**
	 * @param array<string, string> $parameters
	 * @return array<mixed>
	 * @throws MetaFailure
	 */
	private function request(string $method, string $path, #[\SensitiveParameter] array $parameters): array
	{
		if ($this->appId === '') {
			throw new MetaFailure('META_APP_ID is not set; no Graph request is sent.', uncertain: false, transient: false);
		}

		$write = $method === 'POST';
		$url = self::Host . '/' . $this->version . $path;
		$curl = curl_init($write ? $url : $url . '?' . http_build_query($parameters));
		curl_setopt_array($curl, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => $this->timeoutSeconds,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_HTTPHEADER => [
				'Accept: application/json',
			],
		] + ($write ? [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => http_build_query($parameters),
		] : [
			CURLOPT_HTTPGET => true,
		]));
		$response = curl_exec($curl);
		$operation = $method . ' ' . self::operation($path);
		if (!is_string($response)) {
			// No connection at all: nothing reached Meta, a retry is safe.
			if (in_array(curl_errno($curl), [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT], true)) {
				throw new MetaFailure("Graph $operation could not connect.", uncertain: false, transient: true, httpStatus: 0);
			}

			throw MetaFailure::fromGraph($operation, 0, null, $write);
		}

		$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		try {
			$body = Json::decode($response, forceArrays: true);
		} catch (JsonException) {
			$body = null;
		}

		if ($status !== 200 || !is_array($body)) {
			throw MetaFailure::fromGraph($operation, $status, is_array($body) ? $body : null, $write);
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
