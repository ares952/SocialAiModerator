<?php

declare(strict_types=1);

namespace App\Monitoring;


/**
 * One current problem; translated as alarm.<code> with the parameters.
 */
final readonly class Alarm
{
	/**
	 * @param array<string, string|int> $params no customer content
	 */
	public function __construct(
		public string $code,
		public array $params = [],
	) {
	}


	public function describe(): string
	{
		$params = [];
		foreach ($this->params as $name => $value) {
			$params[] = "$name=$value";
		}

		return $params === [] ? $this->code : $this->code . ' (' . implode(', ', $params) . ')';
	}
}
