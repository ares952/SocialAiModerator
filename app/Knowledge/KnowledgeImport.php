<?php

declare(strict_types=1);

namespace App\Knowledge;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use Nette\Database\Explorer;
use Nette\Utils\Json;
use Nette\Utils\JsonException;


/**
 * Imports a knowledge file (docs/knowledge-format.md) as drafts: all or nothing, unchanged
 * versions are skipped, nothing is published or deleted. Errors name the field, never the text.
 */
final class KnowledgeImport
{
	public const Format = 'social-ai-moderator.knowledge';
	public const FormatVersion = 1;


	public function __construct(
		private readonly Explorer $database,
		private readonly KnowledgeBase $knowledge,
		private readonly AuditLog $audit,
	) {
	}


	/**
	 * @return array{created: int, skipped: int}
	 * @throws InvalidKnowledge
	 */
	public function import(string $json, int $operatorId): array
	{
		try {
			$file = Json::decode($json, forceArrays: true);
		} catch (JsonException) {
			throw new InvalidKnowledge('knowledge.import.error.json');
		}
		if (!is_array($file) || ($file['format'] ?? null) !== self::Format || ($file['format_version'] ?? null) !== self::FormatVersion) {
			throw new InvalidKnowledge('knowledge.import.error.format');
		}
		$source = self::optionalText($file, 'source', '~^[a-z0-9_-]{1,50}$~');
		$exportedAt = self::optionalText($file, 'exported_at', '~^[0-9T:.+Z-]{10,35}$~');
		$items = $file['sources'] ?? null;
		if (!is_array($items) || !array_is_list($items) || $items === []) {
			throw new InvalidKnowledge('knowledge.import.error.field', 'sources');
		}

		$result = $this->database->transaction(function () use ($items, $operatorId): array {
			$result = [
				'created' => 0,
				'skipped' => 0,
			];
			$keys = [];
			foreach ($items as $index => $item) {
				$field = "sources[$index]";
				if (!is_array($item)) {
					throw new InvalidKnowledge('knowledge.import.error.field', $field);
				}
				$key = self::requiredText($item, 'key', $field);
				if (isset($keys[$key])) {
					throw new InvalidKnowledge('knowledge.import.error.duplicate', "$field.key");
				}
				$keys[$key] = true;
				$type = SourceType::tryFrom(self::requiredText($item, 'type', $field))
					?? throw new InvalidKnowledge('knowledge.import.error.field', "$field.type");
				$validFrom = self::date($item, 'valid_from', $field);
				$validTo = self::date($item, 'valid_to', $field);
				$locales = $item['locales'] ?? null;
				if (!is_array($locales) || $locales === [] || array_is_list($locales)) {
					throw new InvalidKnowledge('knowledge.import.error.field', "$field.locales");
				}

				foreach ($locales as $locale => $text) {
					$textField = "$field.locales.$locale";
					if (!is_array($text)) {
						throw new InvalidKnowledge('knowledge.import.error.field', $textField);
					}
					$title = trim(self::requiredText($text, 'title', $textField));
					$content = trim(self::requiredText($text, 'content', $textField));
					if ($this->knowledge->unchanged($key, (string) $locale, $type, $title, $content, $validFrom, $validTo)) {
						$result['skipped']++;
						continue;
					}
					try {
						$this->knowledge->createDraft($key, (string) $locale, $type, $title, $content, $validFrom, $validTo, $operatorId);
					} catch (InvalidKnowledge $e) {
						throw new InvalidKnowledge($e->getMessage(), $textField);
					}
					$result['created']++;
				}
			}

			return $result;
		});

		$this->audit->write(
			$operatorId,
			AuditAction::KnowledgeImport,
			'knowledge',
			null,
			"created {$result['created']}, skipped {$result['skipped']}, source " . ($source ?? '-') . ', exported_at ' . ($exportedAt ?? '-'),
		);

		return $result;
	}


	/**
	 * @param array<mixed> $data
	 */
	private static function requiredText(array $data, string $name, string $field): string
	{
		$value = $data[$name] ?? null;
		if (!is_string($value) || trim($value) === '') {
			throw new InvalidKnowledge('knowledge.import.error.field', "$field.$name");
		}

		return $value;
	}


	/**
	 * @param array<mixed> $data
	 */
	private static function optionalText(array $data, string $name, string $pattern): ?string
	{
		$value = $data[$name] ?? null;
		if ($value !== null && (!is_string($value) || !preg_match($pattern, $value))) {
			throw new InvalidKnowledge('knowledge.import.error.field', $name);
		}

		return $value;
	}


	/**
	 * Both validity fields are required; null means no limit.
	 *
	 * @param array<mixed> $data
	 */
	private static function date(array $data, string $name, string $field): ?\DateTimeImmutable
	{
		if (!array_key_exists($name, $data)) {
			throw new InvalidKnowledge('knowledge.import.error.field', "$field.$name");
		}
		$value = $data[$name];
		if ($value === null) {
			return null;
		}
		$date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
		if ($date === false || $date->format('Y-m-d') !== $value) {
			throw new InvalidKnowledge('knowledge.import.error.field', "$field.$name");
		}

		return $date;
	}
}
