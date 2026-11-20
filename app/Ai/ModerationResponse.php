<?php

declare(strict_types=1);

namespace App\Ai;


final readonly class ModerationResponse
{
	/**
	 * @param list<string> $categories flagged categories
	 */
	public function __construct(
		public bool $flagged,
		public array $categories,
	) {
	}


	/**
	 * @param array<mixed> $body decoded response of POST /v1/moderations
	 * @throws OpenAiFailure
	 */
	public static function fromApi(array $body): self
	{
		$result = $body['results'][0] ?? null;
		if (!is_array($result) || !is_bool($result['flagged'] ?? null)) {
			throw new OpenAiFailure('Unexpected moderation response.', transient: false);
		}

		$categories = [];
		foreach (is_array($result['categories'] ?? null) ? $result['categories'] : [] as $name => $flagged) {
			if ($flagged === true) {
				$categories[] = (string) $name;
			}
		}

		return new self($result['flagged'], $categories);
	}
}
