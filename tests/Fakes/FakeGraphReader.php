<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Meta\GraphReader;
use App\Meta\MetaFailure;
use App\Meta\TokenInfo;


/**
 * Scripted Graph reads: answers token checks from a map token => TokenInfo or MetaFailure and
 * records the tokens it was asked about; never calls the network.
 */
final class FakeGraphReader implements GraphReader
{
	/** @var list<string> */
	public array $checked = [];

	/** @var array<string, TokenInfo|MetaFailure> */
	private array $answers = [];


	public function answer(string $token, TokenInfo|MetaFailure $answer): self
	{
		$this->answers[$token] = $answer;

		return $this;
	}


	public function debugToken(#[\SensitiveParameter] string $token): TokenInfo
	{
		$this->checked[] = $token;
		$answer = $this->answers[$token] ?? throw new \LogicException('No scripted answer for this token.');
		if ($answer instanceof MetaFailure) {
			throw $answer;
		}

		return $answer;
	}
}
