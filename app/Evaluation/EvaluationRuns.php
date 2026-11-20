<?php

declare(strict_types=1);

namespace App\Evaluation;

use App\Ai\Classifier;
use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Moderation\Category;
use App\Moderation\Policies;
use App\Queue\JobQueue;
use App\Queue\JobType;
use Nette\Database\Explorer;
use Nette\Database\Row;


/**
 * Runs of the classifier and the PolicyEngine over the whole evaluation set. A run snapshots the
 * model, prompt version, policy version and the labels; the worker evaluates each sample as a
 * separate job (AI calls never run in an HTTP request).
 */
final class EvaluationRuns
{
	public function __construct(
		private readonly Explorer $database,
		private readonly Classifier $classifier,
		private readonly Policies $policies,
		private readonly JobQueue $queue,
		private readonly AuditLog $audit,
	) {
	}


	/**
	 * @throws InvalidEvaluation
	 */
	public function start(int $operatorId): int
	{
		if ($this->classifier->model() === '') {
			throw new InvalidEvaluation('evaluation.error.no-model');
		}

		return $this->database->transaction(function () use ($operatorId): int {
			$samples = $this->database->query(
				'SELECT id, expected_category, expected_action FROM evaluation_samples ORDER BY id',
			)->fetchAll();
			if ($samples === []) {
				throw new InvalidEvaluation('evaluation.error.no-samples');
			}

			$this->database->query('INSERT INTO evaluation_runs ?', [
				'model' => $this->classifier->model(),
				'prompt_version' => Classifier::PromptVersion,
				'policy_version' => $this->policies->active()->version,
				'sample_count' => count($samples),
				'created_by' => $operatorId,
			]);
			$runId = (int) $this->database->getInsertId();

			foreach ($samples as $sample) {
				$this->database->query('INSERT INTO evaluation_results ?', [
					'run_id' => $runId,
					'sample_id' => $sample->id,
					'expected_category' => $sample->expected_category,
					'expected_action' => $sample->expected_action,
					'status' => ResultStatus::Pending->value,
				]);
				$this->queue->push(JobType::EvaluateSample, (int) $this->database->getInsertId());
			}
			$this->audit->write($operatorId, AuditAction::EvaluationRun, 'evaluation_run', $runId);

			return $runId;
		});
	}


	/**
	 * @return list<Row> newest first, with progress and accuracy counters
	 */
	public function all(): array
	{
		return $this->database->query(
			'SELECT r.id, r.model, r.prompt_version, r.policy_version, r.sample_count, r.created_at,
				o.name AS created_by_name,
				SUM(x.status = ?) AS done, SUM(x.status = ?) AS failed,
				SUM(x.status = ? AND x.category = x.expected_category) AS category_correct,
				SUM(x.status = ? AND x.action = x.expected_action) AS action_correct
			FROM evaluation_runs r
			LEFT JOIN operators o ON o.id = r.created_by
			LEFT JOIN evaluation_results x ON x.run_id = r.id
			GROUP BY r.id
			ORDER BY r.id DESC',
			ResultStatus::Done->value,
			ResultStatus::Failed->value,
			ResultStatus::Done->value,
			ResultStatus::Done->value,
		)->fetchAll();
	}


	public function find(int $id): ?Row
	{
		return $this->database->query(
			'SELECT id, model, prompt_version, policy_version, sample_count, created_at FROM evaluation_runs WHERE id = ?',
			$id,
		)->fetch();
	}


	/**
	 * Metrics of a run. The critical counters must be zero before automatic actions are allowed:
	 * hidden legitimate criticism and handoff topics that were not handed off.
	 *
	 * @return array{
	 *     total: int, done: int, failed: int, pending: int,
	 *     categoryCorrect: int, actionCorrect: int, automatic: int,
	 *     criticismHidden: int, missedHandoff: int, falseHide: int, missedHide: int,
	 *     inputTokens: int, outputTokens: int,
	 *     confusion: array<string, array<string, int>>
	 * }
	 */
	public function report(int $runId): array
	{
		$handoff = array_map(
			fn(Category $category) => $category->value,
			array_values(array_filter(Category::cases(), fn(Category $category) => $category->requiresHandoff())),
		);
		$row = $this->database->query(
			'SELECT COUNT(*) AS total, SUM(status = ?) AS done, SUM(status = ?) AS failed, SUM(status = ?) AS pending,
				SUM(status = ? AND category = expected_category) AS category_correct,
				SUM(status = ? AND action = expected_action) AS action_correct,
				SUM(status = ? AND automatic = 1) AS automatic,
				SUM(status = ? AND expected_category = ? AND action = ?) AS criticism_hidden,
				SUM(status = ? AND expected_category IN (?) AND action <> ?) AS missed_handoff,
				SUM(status = ? AND action = ? AND expected_action <> ?) AS false_hide,
				SUM(status = ? AND expected_action = ? AND action <> ?) AS missed_hide,
				SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens
			FROM evaluation_results WHERE run_id = ?',
			ResultStatus::Done->value,
			ResultStatus::Failed->value,
			ResultStatus::Pending->value,
			ResultStatus::Done->value,
			ResultStatus::Done->value,
			ResultStatus::Done->value,
			ResultStatus::Done->value,
			Category::LegitimateCriticism->value,
			'hide',
			ResultStatus::Done->value,
			$handoff,
			'handoff',
			ResultStatus::Done->value,
			'hide',
			'hide',
			ResultStatus::Done->value,
			'hide',
			'hide',
			$runId,
		)->fetch();

		$confusion = [];
		foreach ($this->database->query(
			'SELECT expected_category, category, COUNT(*) AS cnt FROM evaluation_results
			WHERE run_id = ? AND status = ? GROUP BY expected_category, category',
			$runId,
			ResultStatus::Done->value,
		) as $cell) {
			$confusion[$cell->expected_category][$cell->category] = (int) $cell->cnt;
		}

		return [
			'total' => (int) $row?->total,
			'done' => (int) $row?->done,
			'failed' => (int) $row?->failed,
			'pending' => (int) $row?->pending,
			'categoryCorrect' => (int) $row?->category_correct,
			'actionCorrect' => (int) $row?->action_correct,
			'automatic' => (int) $row?->automatic,
			'criticismHidden' => (int) $row?->criticism_hidden,
			'missedHandoff' => (int) $row?->missed_handoff,
			'falseHide' => (int) $row?->false_hide,
			'missedHide' => (int) $row?->missed_hide,
			'inputTokens' => (int) $row?->input_tokens,
			'outputTokens' => (int) $row?->output_tokens,
			'confusion' => $confusion,
		];
	}


	/**
	 * @return list<Row>
	 */
	public function results(int $runId): array
	{
		return $this->database->query(
			'SELECT id, sample_id, expected_category, expected_action, status, category, language, confidence,
				spam_probability, recommended_action, action, automatic, reasons_json, moderation_flagged, error
			FROM evaluation_results WHERE run_id = ? ORDER BY sample_id',
			$runId,
		)->fetchAll();
	}
}
