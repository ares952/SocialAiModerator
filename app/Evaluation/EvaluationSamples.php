<?php

declare(strict_types=1);

namespace App\Evaluation;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Core\Encryptor;
use App\Meta\Platform;
use App\Moderation\Action;
use App\Moderation\Category;
use App\Moderation\ContentKind;
use Nette\Database\Explorer;
use Nette\Database\Row;


/**
 * The hand-labelled evaluation set (specification §9.1: 100-200 samples in CS/EN/ES, including
 * legitimate criticism and prompt injection). Texts are encrypted at rest.
 */
final class EvaluationSamples
{
	public const TextContext = 'evaluation_samples.text';


	public function __construct(
		private readonly Explorer $database,
		private readonly Encryptor $encryptor,
		private readonly AuditLog $audit,
	) {
	}


	public function count(): int
	{
		return (int) $this->database->query('SELECT COUNT(*) FROM evaluation_samples')->fetchField();
	}


	/**
	 * @return list<Row> with decrypted `text`
	 */
	public function all(): array
	{
		$rows = $this->database->query(
			'SELECT id, platform, kind, language, text_encrypted, expected_category, expected_action, note, updated_at
			FROM evaluation_samples ORDER BY id',
		)->fetchAll();

		return array_map($this->decrypted(...), $rows);
	}


	public function find(int $id): ?Sample
	{
		$row = $this->database->query(
			'SELECT platform, kind, language, text_encrypted, expected_category, expected_action, note
			FROM evaluation_samples WHERE id = ?',
			$id,
		)->fetch();

		return $row === null ? null : $this->sample($row);
	}


	public function create(Sample $sample, ?int $operatorId): int
	{
		$this->database->query('INSERT INTO evaluation_samples ?', $this->values($sample) + [
			'created_by' => $operatorId,
		]);
		$id = (int) $this->database->getInsertId();
		$this->audit->write($operatorId, AuditAction::SampleCreate, 'evaluation_sample', $id);

		return $id;
	}


	public function update(int $id, Sample $sample, int $operatorId): void
	{
		$this->database->query('UPDATE evaluation_samples SET ? WHERE id = ?', $this->values($sample), $id);
		$this->audit->write($operatorId, AuditAction::SampleUpdate, 'evaluation_sample', $id);
	}


	public function delete(int $id, int $operatorId): void
	{
		if ($this->database->query('DELETE FROM evaluation_samples WHERE id = ?', $id)->getRowCount() > 0) {
			$this->audit->write($operatorId, AuditAction::SampleDelete, 'evaluation_sample', $id);
		}
	}


	/**
	 * @return array<string, mixed>
	 */
	private function values(Sample $sample): array
	{
		return [
			'platform' => $sample->platform->value,
			'kind' => $sample->kind->value,
			'language' => $sample->language,
			'text_encrypted' => $this->encryptor->encrypt($sample->text, self::TextContext),
			'expected_category' => $sample->expectedCategory->value,
			'expected_action' => $sample->expectedAction->value,
			'note' => $sample->note === null || trim($sample->note) === '' ? null : mb_substr(trim($sample->note), 0, 500),
		];
	}


	private function sample(Row $row): Sample
	{
		return new Sample(
			platform: Platform::from($row->platform),
			kind: ContentKind::from($row->kind),
			language: $row->language,
			text: $this->encryptor->decrypt($row->text_encrypted, self::TextContext),
			expectedCategory: Category::from($row->expected_category),
			expectedAction: Action::from($row->expected_action),
			note: $row->note,
		);
	}


	private function decrypted(Row $row): Row
	{
		$row->text = $this->encryptor->decrypt($row->text_encrypted, self::TextContext);
		unset($row->text_encrypted);

		return $row;
	}
}
