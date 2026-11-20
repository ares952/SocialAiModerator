<?php

declare(strict_types=1);

namespace App\Translation;

use Nette\Database\Explorer;
use Nette\Localization;


/**
 * UI translator backed by the translations table. Usage: {_'key'}, {_'key', $count},
 * {_'key', $count, [name => value]}; %count% and %name% placeholders are replaced.
 * A missing key is rendered as the key itself so it stays visible.
 */
final class Translator implements Localization\Translator
{
	/** @var array<string, array<int, string>>|null */
	private ?array $texts = null;

	private string $language;


	/**
	 * @param list<string> $languages supported codes, the first one is the default
	 */
	public function __construct(
		private readonly Explorer $database,
		private readonly array $languages,
	) {
		$this->language = $languages[0];
	}


	public function setLanguage(string $language): void
	{
		if (!in_array($language, $this->languages, true)) {
			throw new \InvalidArgumentException("Unsupported language '$language'.");
		}
		$this->language = $language;
		$this->texts = null;
	}


	public function getLanguage(): string
	{
		return $this->language;
	}


	public function translate(string|\Stringable $message, mixed ...$parameters): string
	{
		$key = (string) $message;
		$count = $parameters[0] ?? 1;
		$replacements = $parameters[1] ?? [];
		if (!is_int($count) || !is_array($replacements)) {
			throw new \InvalidArgumentException("Translation '$key' expects an int count and an array of replacements.");
		}

		$this->texts ??= $this->load();
		$text = $this->texts[$key][PluralRule::form($this->language, $count)] ?? $key;

		$placeholders = [
			'%count%' => (string) $count,
		];
		foreach ($replacements as $name => $value) {
			$placeholders["%$name%"] = (string) $value;
		}

		return strtr($text, $placeholders);
	}


	/**
	 * @return array<string, array<int, string>>
	 */
	private function load(): array
	{
		$rows = $this->database->query(
			'SELECT t.`key`, t.plurality, t.text
			FROM translations t
			JOIN languages l ON l.id = t.language_id
			WHERE l.code = ?',
			$this->language,
		);

		$texts = [];
		foreach ($rows as $row) {
			$texts[$row->key][$row->plurality] = $row->text;
		}

		return $texts;
	}
}
