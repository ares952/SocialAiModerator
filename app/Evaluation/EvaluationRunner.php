<?php

declare(strict_types=1);

namespace App\Evaluation;

use App\Ai\Classifier;
use App\Ai\InvalidModelOutput;
use App\Ai\OpenAiFailure;
use App\Moderation\AutomationContext;
use App\Moderation\CustomerContent;
use App\Moderation\Policies;
use App\Moderation\PolicyEngine;
use Nette\Database\Explorer;
use Nette\Database\SqlLiteral;
use Nette\Utils\Json;
use Nette\Utils\Strings;


/**
 * Worker step for one sample of a run (job evaluation.sample): classify, decide with the run's
 * policy version, store. Evaluation assumes no pause and an active account, so `automatic` shows
 * what the policy itself would allow.
 */
final class EvaluationRunner
{
	public function __construct(
		private readonly Explorer $database,
		private readonly EvaluationSamples $samples,
		private readonly Classifier $classifier,
		private readonly Policies $policies,
		private readonly PolicyEngine $engine,
	) {
	}


	/**
	 * @param bool $lastAttempt a transient AI failure is retried by the queue until the last attempt
	 * @throws OpenAiFailure transient failure before the last attempt
	 */
	public function evaluate(int $resultId, bool $lastAttempt): void
	{
		$result = $this->database->query(
			'SELECT x.status, x.sample_id, r.policy_version FROM evaluation_results x
			JOIN evaluation_runs r ON r.id = x.run_id WHERE x.id = ?',
			$resultId,
		)->fetch();
		if ($result === null || $result->status !== ResultStatus::Pending->value) {
			return;
		}

		$sample = $result->sample_id === null ? null : $this->samples->find($result->sample_id);
		$policy = $this->policies->version($result->policy_version);
		if ($sample === null || $policy === null) {
			$this->fail($resultId, 'The sample or the policy version no longer exists.');

			return;
		}

		$content = new CustomerContent(
			$sample->platform,
			$sample->kind,
			$sample->text,
		);
		try {
			$classified = $this->classifier->classify($content);
		} catch (OpenAiFailure $e) {
			if ($e->transient && !$lastAttempt) {
				throw $e;
			}
			$this->fail($resultId, $e::class . ': ' . $e->getMessage());

			return;
		} catch (InvalidModelOutput $e) {
			$this->fail($resultId, $e::class . ': ' . $e->getMessage());

			return;
		}

		$decision = $this->engine->decide(
			$classified->classification,
			$content,
			$classified->moderationFlagged,
			$policy,
			new AutomationContext(paused: false, accountActive: true),
		);
		$classification = $classified->classification;
		$this->database->query(
			'UPDATE evaluation_results SET ? WHERE id = ?',
			[
				'status' => ResultStatus::Done->value,
				'category' => $classification->category->value,
				'language' => $classification->language,
				'confidence' => $classification->confidence,
				'spam_probability' => $classification->spamProbability,
				'recommended_action' => $classification->recommendedAction->value,
				'action' => $decision->action->value,
				'automatic' => $decision->automatic,
				'reasons_json' => Json::encode($decision->reasons),
				'moderation_flagged' => $classified->moderationFlagged,
				'input_tokens' => $classified->inputTokens,
				'output_tokens' => $classified->outputTokens,
				'completed_at' => new SqlLiteral('NOW()'),
			],
			$resultId,
		);
	}


	private function fail(int $resultId, string $error): void
	{
		$this->database->query(
			'UPDATE evaluation_results SET status = ?, error = ?, completed_at = NOW() WHERE id = ?',
			ResultStatus::Failed->value,
			Strings::truncate($error, 500),
			$resultId,
		);
	}
}
