<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Ai\ModerationResponse;
use App\Ai\OpenAiClient;
use App\Ai\OpenAiFailure;
use App\Ai\StructuredResponse;


/**
 * Scripted OpenAI: returns queued outputs or throws queued failures, and records every request.
 */
final class FakeOpenAiClient implements OpenAiClient
{
	/** @var list<array{type: string, model: string, instructions?: string, input: string, schema?: array<string, mixed>}> */
	public array $requests = [];

	/** @var list<array<mixed>|OpenAiFailure> */
	private array $outputs = [];

	private ModerationResponse|OpenAiFailure $moderation;


	public function __construct()
	{
		$this->moderation = new ModerationResponse(false, []);
	}


	/**
	 * @param array<mixed>|OpenAiFailure $output next structured output (a classification) or failure
	 */
	public function queue(array|OpenAiFailure $output): self
	{
		$this->outputs[] = $output;

		return $this;
	}


	public function moderationResult(ModerationResponse|OpenAiFailure $moderation): self
	{
		$this->moderation = $moderation;

		return $this;
	}


	/**
	 * A valid classification with optional overrides.
	 *
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	public static function classification(array $overrides = []): array
	{
		return $overrides + [
			'category' => 'faq',
			'language' => 'cs',
			'sentiment' => 'neutral',
			'confidence' => 0.97,
			'spam_probability' => 0.01,
			'abuse_type' => 'none',
			'abuse_score' => 0.0,
			'has_substance' => true,
			'recommended_action' => 'reply',
			'requires_human' => false,
			'reason' => 'Question about the price.',
			'knowledge_required' => [],
		];
	}


	public function moderate(string $model, string $input): ModerationResponse
	{
		$this->requests[] = [
			'type' => 'moderation',
			'model' => $model,
			'input' => $input,
		];
		if ($this->moderation instanceof OpenAiFailure) {
			throw $this->moderation;
		}

		return $this->moderation;
	}


	public function structured(
		string $model,
		string $instructions,
		string $input,
		string $schemaName,
		array $schema,
	): StructuredResponse {
		$this->requests[] = [
			'type' => 'structured',
			'model' => $model,
			'instructions' => $instructions,
			'input' => $input,
			'schema' => $schema,
		];
		$output = array_shift($this->outputs) ?? throw new \LogicException('No fake output queued.');
		if ($output instanceof OpenAiFailure) {
			throw $output;
		}

		return new StructuredResponse($model . '-2026', $output, 120, 40);
	}
}
