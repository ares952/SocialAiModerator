<?php

declare(strict_types=1);

namespace App\Knowledge;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use Nette\Database\Explorer;
use Nette\Database\Row;


/**
 * Approved business knowledge for AI replies (specification §5.3). Every edit is a new draft
 * version; an admin publishes one version per key and locale, which archives the previous one.
 * Only published versions valid today (in the business time zone) reach the AI.
 */
final class KnowledgeBase
{
	/**
	 * @param list<string> $locales supported locales of the content
	 */
	public function __construct(
		private readonly Explorer $database,
		private readonly AuditLog $audit,
		private readonly array $locales,
		private readonly string $timezone,
	) {
	}


	/**
	 * @return list<string>
	 */
	public function locales(): array
	{
		return $this->locales;
	}


	/**
	 * One row per key and locale: the latest version (id, title) and the published version number.
	 *
	 * @return list<Row>
	 */
	public function overview(): array
	{
		return $this->database->query(
			'SELECT s.id, s.`key`, s.locale, s.title, s.version AS latest_version, s.status AS latest_status,
				(SELECT p.version FROM knowledge_sources p
					WHERE p.`key` = s.`key` AND p.locale = s.locale AND p.status = ?) AS published_version
			FROM knowledge_sources s
			WHERE s.version = (SELECT MAX(m.version) FROM knowledge_sources m WHERE m.`key` = s.`key` AND m.locale = s.locale)
			ORDER BY s.`key`, s.locale',
			SourceStatus::Published->value,
		)->fetchAll();
	}


	/**
	 * @return list<Row> newest first
	 */
	public function versions(string $key, string $locale): array
	{
		return $this->database->query(
			'SELECT s.id, s.version, s.status, s.title, s.source_type, s.valid_from, s.valid_to, s.created_at,
				s.approved_at, c.name AS created_by_name, a.name AS approved_by_name
			FROM knowledge_sources s
			LEFT JOIN operators c ON c.id = s.created_by
			LEFT JOIN operators a ON a.id = s.approved_by
			WHERE s.`key` = ? AND s.locale = ?
			ORDER BY s.version DESC',
			$key,
			$locale,
		)->fetchAll();
	}


	public function find(int $id): ?Row
	{
		return $this->database->query(
			'SELECT id, `key`, locale, version, source_type, title, content, valid_from, valid_to, status
			FROM knowledge_sources WHERE id = ?',
			$id,
		)->fetch();
	}


	/**
	 * @throws InvalidKnowledge
	 */
	public function createDraft(
		string $key,
		string $locale,
		SourceType $type,
		string $title,
		string $content,
		?\DateTimeImmutable $validFrom,
		?\DateTimeImmutable $validTo,
		int $operatorId,
	): int {
		if (!preg_match('~^[a-z0-9_]{1,100}$~', $key)) {
			throw new InvalidKnowledge('knowledge.error.key');
		}
		if (!in_array($locale, $this->locales, true)) {
			throw new InvalidKnowledge('knowledge.error.locale');
		}
		if (trim($title) === '' || trim($content) === '') {
			throw new InvalidKnowledge('form.error.required');
		}
		if ($validFrom !== null && $validTo !== null && $validFrom > $validTo) {
			throw new InvalidKnowledge('knowledge.error.validity');
		}

		return $this->database->transaction(function () use ($key, $locale, $type, $title, $content, $validFrom, $validTo, $operatorId): int {
			$version = 1 + (int) $this->database->query(
				'SELECT MAX(version) FROM knowledge_sources WHERE `key` = ? AND locale = ? FOR UPDATE',
				$key,
				$locale,
			)->fetchField();
			$this->database->query('INSERT INTO knowledge_sources ?', [
				'key' => $key,
				'locale' => $locale,
				'version' => $version,
				'source_type' => $type->value,
				'title' => trim($title),
				'content' => trim($content),
				'valid_from' => $validFrom?->format('Y-m-d'),
				'valid_to' => $validTo?->format('Y-m-d'),
				'status' => SourceStatus::Draft->value,
				'created_by' => $operatorId,
			]);
			$id = (int) $this->database->getInsertId();
			$this->audit->write($operatorId, AuditAction::KnowledgeCreate, 'knowledge', $id, "$key/$locale v$version");

			return $id;
		});
	}


	/**
	 * Publishes a version and archives the previously published one of the same key and locale.
	 *
	 * @throws InvalidKnowledge
	 */
	public function publish(int $id, int $operatorId): void
	{
		$this->database->transaction(function () use ($id, $operatorId): void {
			$source = $this->database->query('SELECT `key`, locale, status FROM knowledge_sources WHERE id = ? FOR UPDATE', $id)->fetch()
				?? throw new InvalidKnowledge('knowledge.error.not-found');
			if ($source->status === SourceStatus::Published->value) {
				return;
			}

			$this->database->query(
				'UPDATE knowledge_sources SET status = ? WHERE `key` = ? AND locale = ? AND status = ?',
				SourceStatus::Archived->value,
				$source->key,
				$source->locale,
				SourceStatus::Published->value,
			);
			$this->database->query(
				'UPDATE knowledge_sources SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?',
				SourceStatus::Published->value,
				$operatorId,
				$id,
			);
			$this->audit->write($operatorId, AuditAction::KnowledgePublish, 'knowledge', $id);
		});
	}


	/**
	 * Withdraws a published version; the key then has no current knowledge.
	 */
	public function unpublish(int $id, int $operatorId): void
	{
		$changed = $this->database->query(
			'UPDATE knowledge_sources SET status = ? WHERE id = ? AND status = ?',
			SourceStatus::Archived->value,
			$id,
			SourceStatus::Published->value,
		)->getRowCount();

		if ($changed > 0) {
			$this->audit->write($operatorId, AuditAction::KnowledgeUnpublish, 'knowledge', $id);
		}
	}


	/**
	 * Published knowledge valid today, for building a reply.
	 *
	 * @param list<string> $keys
	 * @return list<Row> id, key, version, title, content
	 */
	public function current(array $keys, string $locale): array
	{
		if ($keys === []) {
			return [];
		}

		return $this->database->query(
			'SELECT id, `key`, version, title, content FROM knowledge_sources
			WHERE status = ? AND locale = ? AND `key` IN (?)
				AND (valid_from IS NULL OR valid_from <= ?) AND (valid_to IS NULL OR valid_to >= ?)
			ORDER BY `key`',
			SourceStatus::Published->value,
			$locale,
			$keys,
			$this->today(),
			$this->today(),
		)->fetchAll();
	}


	/**
	 * Keys with published knowledge valid today in any locale, with a title, for the classifier.
	 *
	 * @return array<string, string> key => title
	 */
	public function publishedKeys(): array
	{
		return $this->database->query(
			'SELECT `key`, MIN(title) AS title FROM knowledge_sources
			WHERE status = ? AND (valid_from IS NULL OR valid_from <= ?) AND (valid_to IS NULL OR valid_to >= ?)
			GROUP BY `key`
			ORDER BY `key`',
			SourceStatus::Published->value,
			$this->today(),
			$this->today(),
		)->fetchPairs('key', 'title');
	}


	private function today(): string
	{
		return (new \DateTimeImmutable('now', new \DateTimeZone($this->timezone)))->format('Y-m-d');
	}
}
