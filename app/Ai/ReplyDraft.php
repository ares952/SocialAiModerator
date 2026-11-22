<?php

declare(strict_types=1);

namespace App\Ai;


/**
 * A reply suggestion written by the ReplyGenerator; still needs a human approval.
 */
final readonly class ReplyDraft
{
	/**
	 * @param list<array{id: int, key: string, version: int}> $knowledge sources the reply relies on
	 */
	public function __construct(
		public string $text,
		public array $knowledge,
		public string $model,
		public string $promptVersion,
		public int $inputTokens,
		public int $outputTokens,
	) {
	}
}
