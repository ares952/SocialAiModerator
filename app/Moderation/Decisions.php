<?php

declare(strict_types=1);

namespace App\Moderation;

use App\Ai\ClassifierResult;
use App\Core\Encryptor;
use Nette\Database\Explorer;
use Nette\Database\Row;
use Nette\Utils\Json;
use Nette\Utils\Strings;


/**
 * moderation_decisions: every decision about an event with the model, prompt and policy version
 * it was made with (specification §6). The model's explanation may paraphrase the customer, so
 * it is encrypted like the event payload.
 */
final class Decisions
{
	public const ReasonContext = 'moderation_decisions.reason';


	public function __construct(
		private readonly Explorer $database,
		private readonly Encryptor $encryptor,
	) {
	}


	/**
	 * Whether the event already has a decision based on a classification; a failed classification
	 * does not count, so it can be repeated.
	 */
	public function classified(int $eventId): bool
	{
		return (bool) $this->database->query(
			'SELECT 1 FROM moderation_decisions WHERE event_id = ? AND category IS NOT NULL LIMIT 1',
			$eventId,
		)->fetchField();
	}


	/**
	 * @param ClassifierResult|null $classified null when the classification failed
	 * @param string|null $error why it failed; never customer content
	 * @return int ID of the decision
	 */
	public function record(
		int $eventId,
		?ClassifierResult $classified,
		Decision $decision,
		string $model,
		string $promptVersion,
		?string $error,
	): int {
		$classification = $classified?->classification;
		$this->database->query('INSERT INTO moderation_decisions ?', [
			'event_id' => $eventId,
			'model' => $classified === null ? $model : $classified->model,
			'prompt_version' => $classified === null ? $promptVersion : $classified->promptVersion,
			'policy_version' => $decision->policyVersion,
			'category' => $classification?->category->value,
			'confidence' => $classification?->confidence,
			'action' => $decision->action->value,
			'automatic' => $decision->automatic,
			'result_json' => Json::encode([
				'classification' => $classification === null ? null : [
					'language' => $classification->language,
					'sentiment' => $classification->sentiment->value,
					'spam_probability' => $classification->spamProbability,
					'abuse_type' => $classification->abuseType->value,
					'abuse_score' => $classification->abuseScore,
					'has_substance' => $classification->hasSubstance,
					'recommended_action' => $classification->recommendedAction->value,
					'requires_human' => $classification->requiresHuman,
					'knowledge_required' => $classification->knowledgeRequired,
				],
				'moderation' => $classified === null ? null : [
					'flagged' => $classified->moderationFlagged,
					'categories' => $classified->moderationCategories,
				],
				'reasons' => $decision->reasons,
			]),
			'reason_encrypted' => $classification === null || $classification->reason === ''
				? null
				: $this->encryptor->encrypt($classification->reason, self::ReasonContext),
			'input_tokens' => $classified?->inputTokens,
			'output_tokens' => $classified?->outputTokens,
			'error' => $error === null ? null : Strings::truncate($error, 500),
		]);

		return (int) $this->database->getInsertId();
	}


	/**
	 * Newest first; the model explanation stays encrypted (reason()).
	 *
	 * @return list<Row>
	 */
	public function forEvent(int $eventId): array
	{
		return $this->database->query(
			'SELECT id, model, prompt_version, policy_version, category, confidence, action, automatic,
				result_json, reason_encrypted, input_tokens, output_tokens, error, created_at
			FROM moderation_decisions WHERE event_id = ? ORDER BY id DESC',
			$eventId,
		)->fetchAll();
	}


	/**
	 * The model's explanation of a decision row from forEvent(); customer-related content.
	 */
	public function reason(Row $decision): ?string
	{
		return $decision->reason_encrypted === null
			? null
			: $this->encryptor->decrypt($decision->reason_encrypted, self::ReasonContext);
	}
}
