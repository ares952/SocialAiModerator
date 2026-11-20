<?php

declare(strict_types=1);

namespace App\Ai;

use Nette\Utils\Json;
use Nette\Utils\JsonException;


final readonly class StructuredResponse
{
	/**
	 * @param array<mixed> $output JSON object produced by the model (still to be validated)
	 */
	public function __construct(
		public string $model,
		public array $output,
		public int $inputTokens,
		public int $outputTokens,
	) {
	}


	/**
	 * @param array<mixed> $body decoded response of POST /v1/responses
	 * @throws OpenAiFailure
	 */
	public static function fromApi(array $body): self
	{
		if (($body['status'] ?? null) !== 'completed') {
			throw new OpenAiFailure('Response not completed: ' . (is_string($body['status'] ?? null) ? $body['status'] : 'unknown'), transient: false);
		}

		$text = null;
		foreach (is_array($body['output'] ?? null) ? $body['output'] : [] as $item) {
			foreach (is_array($item['content'] ?? null) ? $item['content'] : [] as $content) {
				if (($content['type'] ?? null) === 'refusal') {
					throw new OpenAiFailure('The model refused to answer.', transient: false);
				}
				if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
					$text = $content['text'];
				}
			}
		}
		if ($text === null) {
			throw new OpenAiFailure('Response without output text.', transient: false);
		}

		try {
			$output = Json::decode($text, forceArrays: true);
		} catch (JsonException) {
			throw new OpenAiFailure('Output text is not JSON.', transient: false);
		}
		if (!is_array($output)) {
			throw new OpenAiFailure('Output is not a JSON object.', transient: false);
		}

		return new self(
			model: is_string($body['model'] ?? null) ? $body['model'] : '',
			output: $output,
			inputTokens: (int) ($body['usage']['input_tokens'] ?? 0),
			outputTokens: (int) ($body['usage']['output_tokens'] ?? 0),
		);
	}
}
