<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Correlation;
use App\Core\Logger;
use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Tracy\ILogger;


/**
 * Application logger writing into the test temp directory, with a readable summary of its lines.
 */
final class TestLog
{
	public readonly Logger $logger;

	private readonly string $file;


	public function __construct(string $name = 'app.log')
	{
		$this->file = TEMP_DIR . '/' . $name;
		$this->logger = new Logger($this->file, new Correlation, new class implements ILogger {
			public function log(mixed $value, string $level = self::INFO): void
			{
			}
		});
	}


	/**
	 * @return list<string> "level: message" of every line
	 */
	public function messages(): array
	{
		if (!is_file($this->file)) {
			return [];
		}

		$messages = [];
		foreach (explode("\n", trim(FileSystem::read($this->file))) as $line) {
			$entry = Json::decode($line, forceArrays: true);
			$messages[] = $entry['level'] . ': ' . $entry['message'];
		}

		return $messages;
	}


	public function clear(): void
	{
		FileSystem::delete($this->file);
	}
}
