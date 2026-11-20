<?php

declare(strict_types=1);

namespace App\Ai;


/**
 * Boundary to the OpenAI API (production: HttpOpenAiClient, tests: FakeOpenAiClient). The AI side
 * has no Meta credentials and its output is only data for the PolicyEngine.
 */
interface OpenAiClient
{
	/**
	 * Moderation endpoint (e.g. omni-moderation-latest).
	 *
	 * @throws OpenAiFailure
	 */
	public function moderate(string $model, string $input): ModerationResponse;


	/**
	 * Responses API with Structured Outputs: the output must match the strict JSON Schema.
	 *
	 * @param array<string, mixed> $schema
	 * @throws OpenAiFailure
	 */
	public function structured(
		string $model,
		string $instructions,
		string $input,
		string $schemaName,
		array $schema,
	): StructuredResponse;
}
