<?php

declare(strict_types=1);

namespace App\Ai;

use App\Knowledge\KnowledgeBase;
use App\Moderation\CustomerContent;
use Nette\Utils\FileSystem;
use Nette\Utils\Json;


/**
 * Classifies a comment or message (specification §5.1, steps 1, 3 and 4): personal data is
 * minimized, the optional moderation endpoint runs first, then the model returns a classification
 * matching the strict schema. The customer text travels only as a JSON value, separated from the
 * versioned instructions. Failures throw; the caller falls back to review (fail closed).
 */
final class Classifier
{
	public const PromptVersion = 'classifier-v2';
	private const SchemaName = 'classification';


	public function __construct(
		private readonly OpenAiClient $client,
		private readonly KnowledgeBase $knowledge,
		private readonly string $model,
		private readonly string $moderationModel,
	) {
	}


	public function model(): string
	{
		return $this->model;
	}


	/**
	 * @throws OpenAiFailure
	 * @throws InvalidModelOutput
	 */
	public function classify(CustomerContent $content): ClassifierResult
	{
		if ($this->model === '') {
			throw new OpenAiFailure('OPENAI_CLASSIFIER_MODEL is not set.', transient: false);
		}

		$text = Redactor::redact($content->text);
		$moderation = $this->moderationModel === ''
			? new ModerationResponse(false, [])
			: $this->client->moderate($this->moderationModel, $text);

		$knowledge = $this->knowledge->publishedKeys();
		$response = $this->client->structured(
			$this->model,
			$this->instructions($knowledge),
			Json::encode([
				'platform' => $content->platform->value,
				'kind' => $content->kind->value,
				'text' => $text,
			]),
			self::SchemaName,
			self::schema(),
		);

		return new ClassifierResult(
			classification: ClassificationParser::parse($response->output, array_map('strval', array_keys($knowledge))),
			moderationFlagged: $moderation->flagged,
			moderationCategories: $moderation->categories,
			model: $response->model !== '' ? $response->model : $this->model,
			promptVersion: self::PromptVersion,
			inputTokens: $response->inputTokens,
			outputTokens: $response->outputTokens,
		);
	}


	/**
	 * @param array<string, string> $knowledge published key => title
	 */
	private function instructions(array $knowledge): string
	{
		$keys = [];
		foreach ($knowledge as $key => $title) {
			$keys[] = "- $key: $title";
		}

		return strtr(FileSystem::read(__DIR__ . '/prompts/' . self::PromptVersion . '.md'), [
			'%knowledge%' => $keys === [] ? '- (none)' : implode("\n", $keys),
		]);
	}


	/**
	 * @return array<string, mixed>
	 */
	private static function schema(): array
	{
		$schema = Json::decode(FileSystem::read(__DIR__ . '/schemas/classification-v2.json'), forceArrays: true);
		if (!is_array($schema)) {
			throw new \LogicException('Invalid classification schema.');
		}

		return $schema;
	}
}
