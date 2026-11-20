<?php

declare(strict_types=1);

namespace App\Core;


/**
 * Correlation ID that ties together the log lines of one HTTP request or one worker job.
 */
final class Correlation
{
	private string $id;


	public function __construct()
	{
		$this->id = self::generate();
	}


	public function id(): string
	{
		return $this->id;
	}


	/**
	 * Starts a new unit of work (the worker calls it for every job).
	 */
	public function renew(): void
	{
		$this->id = self::generate();
	}


	private static function generate(): string
	{
		return bin2hex(random_bytes(8));
	}
}
