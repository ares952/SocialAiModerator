<?php

declare(strict_types=1);

namespace App\Ai;

use App\Queue\CircuitBreaker;
use App\Queue\Postponed;


/**
 * OpenAI client behind the shared circuit breaker: while it is open, no request is sent and the
 * job is postponed; transient failures count towards opening it.
 */
final class GuardedOpenAiClient implements OpenAiClient
{
	private const Breaker = 'openai';


	public function __construct(
		private readonly OpenAiClient $client,
		private readonly CircuitBreaker $breaker,
	) {
	}


	/**
	 * @throws Postponed
	 */
	public function moderate(string $model, string $input): ModerationResponse
	{
		return $this->guard(fn() => $this->client->moderate($model, $input));
	}


	/**
	 * @throws Postponed
	 */
	public function structured(
		string $model,
		string $instructions,
		string $input,
		string $schemaName,
		array $schema,
	): StructuredResponse {
		return $this->guard(fn() => $this->client->structured($model, $instructions, $input, $schemaName, $schema));
	}


	/**
	 * @template T
	 * @param \Closure(): T $call
	 * @return T
	 * @throws OpenAiFailure
	 * @throws Postponed
	 */
	private function guard(\Closure $call): mixed
	{
		$retryAfter = $this->breaker->retryAfter(self::Breaker);
		if ($retryAfter !== null) {
			throw new Postponed('OpenAI circuit breaker is open.', $retryAfter);
		}

		try {
			$result = $call();
		} catch (OpenAiFailure $e) {
			if ($e->transient) {
				$this->breaker->recordFailure(self::Breaker);
			}
			throw $e;
		}

		$this->breaker->recordSuccess(self::Breaker);

		return $result;
	}
}
