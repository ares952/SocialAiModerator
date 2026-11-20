<?php

declare(strict_types=1);

namespace App\Ai;

use Nette\Utils\Json;
use Nette\Utils\JsonException;


/**
 * OpenAI over HTTPS with ext-curl. Requests are sent with `store: false`; errors carry the HTTP
 * status and the API error type, never the request body.
 */
final class HttpOpenAiClient implements OpenAiClient
{
	private const BaseUrl = 'https://api.openai.com/v1';


	public function __construct(
		#[\SensitiveParameter]
		private readonly string $apiKey,
		private readonly int $timeoutSeconds = 30,
	) {
	}


	public function moderate(string $model, string $input): ModerationResponse
	{
		return ModerationResponse::fromApi($this->post('/moderations', [
			'model' => $model,
			'input' => $input,
		]));
	}


	public function structured(
		string $model,
		string $instructions,
		string $input,
		string $schemaName,
		array $schema,
	): StructuredResponse {
		return StructuredResponse::fromApi($this->post('/responses', [
			'model' => $model,
			'instructions' => $instructions,
			'input' => $input,
			'store' => false,
			'text' => [
				'format' => [
					'type' => 'json_schema',
					'name' => $schemaName,
					'schema' => $schema,
					'strict' => true,
				],
			],
		]));
	}


	/**
	 * @param array<string, mixed> $payload
	 * @return array<mixed>
	 * @throws OpenAiFailure
	 */
	private function post(string $path, array $payload): array
	{
		if ($this->apiKey === '') {
			throw new OpenAiFailure('OPENAI_API_KEY is not set.', transient: false);
		}

		$curl = curl_init(self::BaseUrl . $path);
		curl_setopt_array($curl, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => Json::encode($payload),
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				'Authorization: Bearer ' . $this->apiKey,
			],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => $this->timeoutSeconds,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_FOLLOWLOCATION => false,
		]);
		$response = curl_exec($curl);
		$status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		$error = curl_error($curl);

		if (!is_string($response)) {
			throw new OpenAiFailure("OpenAI request $path failed: $error", transient: true);
		}

		try {
			$body = Json::decode($response, forceArrays: true);
		} catch (JsonException) {
			$body = null;
		}

		if ($status !== 200 || !is_array($body)) {
			$type = is_array($body) && is_string($body['error']['type'] ?? null) ? $body['error']['type'] : 'unknown';
			throw new OpenAiFailure(
				"OpenAI request $path failed with HTTP $status ($type).",
				transient: $status === 429 || $status >= 500 || $status === 0,
			);
		}

		return $body;
	}
}
