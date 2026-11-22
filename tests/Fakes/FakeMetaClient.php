<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Account\AccessToken;
use App\Meta\MetaClient;
use App\Meta\MetaFailure;
use App\Meta\Platform;


/**
 * Scripted Meta: records every write and throws queued failures; never calls the network.
 */
final class FakeMetaClient implements MetaClient
{
	/** @var list<array{platform: Platform, token: string, comment: string, hidden: bool}> */
	public array $writes = [];

	/** @var list<MetaFailure> */
	private array $failures = [];


	public function fail(MetaFailure $failure): self
	{
		$this->failures[] = $failure;

		return $this;
	}


	public function hideComment(Platform $platform, AccessToken $token, string $commentId, bool $hidden): void
	{
		$this->writes[] = [
			'platform' => $platform,
			'token' => $token->value,
			'comment' => $commentId,
			'hidden' => $hidden,
		];
		$failure = array_shift($this->failures);
		if ($failure !== null) {
			throw $failure;
		}
	}
}
