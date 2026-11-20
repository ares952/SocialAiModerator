<?php

declare(strict_types=1);

namespace App\Translation;

use Nette\Utils\FileSystem;
use Nette\Utils\Json;


/**
 * One validated data/language_<code>.json file.
 */
final readonly class TranslationFile
{
	/**
	 * @param array<string, array<int, string>> $texts key => plural form => text
	 */
	private function __construct(
		public string $language,
		public string $hash,
		public array $texts,
	) {
	}


	public static function path(string $dataDir, string $language): string
	{
		return "$dataDir/language_$language.json";
	}


	/**
	 * @throws InvalidTranslationFile
	 */
	public static function load(string $dataDir, string $language): self
	{
		$path = self::path($dataDir, $language);
		$content = FileSystem::read($path);
		$entries = Json::decode($content, forceArrays: true);
		if (!is_array($entries) || !array_is_list($entries)) {
			throw new InvalidTranslationFile("$path: expected a JSON list of translations.");
		}

		$forms = PluralRule::forms($language);
		$texts = [];
		foreach ($entries as $index => $entry) {
			$key = $entry['key'] ?? null;
			$translations = $entry['translations'] ?? null;
			if (!is_string($key) || $key === '' || ($entry['lang'] ?? null) !== $language || !is_array($translations)) {
				throw new InvalidTranslationFile("$path: entry #$index must have a key, lang '$language' and translations.");
			}
			if (isset($texts[$key])) {
				throw new InvalidTranslationFile("$path: duplicate key '$key'.");
			}

			$keys = array_map('intval', array_keys($translations));
			sort($keys);
			if ($keys !== $forms) {
				throw new InvalidTranslationFile("$path: key '$key' must define exactly the plural forms " . implode(', ', $forms) . '.');
			}
			foreach ($translations as $form => $text) {
				if (!is_string($text) || trim($text) === '') {
					throw new InvalidTranslationFile("$path: key '$key' has an empty form $form.");
				}
				$texts[$key][(int) $form] = $text;
			}
		}

		return new self($language, hash('sha256', $content), $texts);
	}
}
