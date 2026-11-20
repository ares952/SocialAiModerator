<?php

declare(strict_types=1);

namespace App\Translation;

use Nette\Database\Explorer;
use Nette\Database\Row;
use Nette\Database\SqlLiteral;


/**
 * Imports data/language_<code>.json into the translations table for every row of `languages`.
 * The JSON files are the source of truth for non-manual rows; rows edited in the administration
 * (is_manual = 1) are never overwritten or removed. A language is skipped when its file hash
 * matches the last import.
 */
final class TranslationImporter
{
	public function __construct(
		private readonly Explorer $database,
		private readonly string $dataDir,
	) {
	}


	/**
	 * Languages whose JSON file changed since the last import.
	 *
	 * @return list<string>
	 */
	public function pending(): array
	{
		$pending = [];
		foreach ($this->languages() as $language) {
			$hash = hash_file('sha256', TranslationFile::path($this->dataDir, $language->code));
			if ($hash !== $language->source_hash) {
				$pending[] = $language->code;
			}
		}

		return $pending;
	}


	/**
	 * @return array<string, ImportResult> language code => result
	 * @throws InvalidTranslationFile
	 */
	public function import(bool $force = false): array
	{
		$results = [];
		foreach ($this->languages() as $language) {
			$file = TranslationFile::load($this->dataDir, $language->code);
			$results[$language->code] = !$force && $file->hash === $language->source_hash
				? ImportResult::unchanged()
				: $this->importFile((int) $language->id, $file);
		}

		return $results;
	}


	/**
	 * @return iterable<Row>
	 */
	private function languages(): iterable
	{
		return $this->database->query('SELECT id, code, source_hash FROM languages ORDER BY id');
	}


	private function importFile(int $languageId, TranslationFile $file): ImportResult
	{
		return $this->database->transaction(function () use ($languageId, $file): ImportResult {
			$changed = 0;
			foreach ($file->texts as $key => $forms) {
				foreach ($forms as $plurality => $text) {
					// Affected rows: 1 = inserted, 2 = updated, 0 = unchanged or manual.
					$changed += $this->database->query(
						'INSERT INTO translations (language_id, `key`, plurality, text) VALUES (?, ?, ?, ?)
						ON DUPLICATE KEY UPDATE text = IF(is_manual = 1, text, VALUE(text))',
						$languageId,
						$key,
						$plurality,
						$text,
					)->getRowCount() > 0 ? 1 : 0;
				}
			}

			$removed = $this->database->table('translations')
				->where('language_id', $languageId)
				->where('is_manual', 0)
				->where('key NOT IN ?', array_keys($file->texts))
				->delete();

			$this->database->table('languages')
				->where('id', $languageId)
				->update([
					'source_hash' => $file->hash,
					'imported_at' => new SqlLiteral('NOW()'),
				]);

			return new ImportResult($changed, $removed);
		});
	}
}
