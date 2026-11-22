<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Ai\InvalidModelOutput;
use App\Ai\OpenAiFailure;
use App\Ai\ReplyGenerator;
use App\Comment\Comments;
use App\Conversation\Conversations;
use App\Core\Logger;
use App\Knowledge\KnowledgeBase;
use Nette\Database\Explorer;
use Nette\Database\Row;
use Nette\Utils\Json;


/**
 * Worker step for a proposed reply (job reply.draft, specification §5.1 step 6): takes the current
 * published knowledge the classification named, in the language of the comment or message, and
 * lets the ReplyGenerator write a draft for a moderator; a message gets the recent conversation
 * as context. A taken-over conversation or a closed window gets no draft. Without knowledge, without an answer, or with an
 * invalid or failed output the reply is blocked and the comment stays with a human (fail closed).
 */
final class ReplyDrafter
{
	public function __construct(
		private readonly Explorer $database,
		private readonly Actions $actions,
		private readonly Comments $comments,
		private readonly Conversations $conversations,
		private readonly KnowledgeBase $knowledge,
		private readonly ReplyGenerator $generator,
		private readonly Logger $logger,
	) {
	}


	/**
	 * @param bool $lastAttempt a transient AI failure is retried by the queue until the last attempt
	 * @throws OpenAiFailure transient failure before the last attempt
	 */
	public function draft(int $actionId, bool $lastAttempt): void
	{
		$action = $this->database->query(
			'SELECT a.comment_id, a.message_id, d.result_json,
				v.human_takeover_at IS NOT NULL AS taken_over, v.window_expires_at > NOW(3) AS window_open
			FROM moderation_actions a
			LEFT JOIN moderation_decisions d ON d.id = a.decision_id
			LEFT JOIN social_messages m ON m.id = a.message_id
			LEFT JOIN social_conversations v ON v.id = m.conversation_id
			WHERE a.id = ? AND a.type = ? AND a.status = ? AND a.text_encrypted IS NULL',
			$actionId,
			ActionType::Reply->value,
			ActionStatus::Pending->value,
		)->fetch();
		if ($action === null) {
			return;
		}

		$messageId = $action->message_id;
		if ($messageId !== null && ($action->taken_over || !$action->window_open)) {
			$this->block($actionId, $action->taken_over ? 'human_takeover' : 'window_expired', null);

			return;
		}

		[$language, $keys] = $this->request($action);
		$text = $messageId === null ? $this->comments->text($action->comment_id) : $this->conversations->text($messageId);
		$sources = $language === null ? [] : $this->sources($keys, $language);
		if ($text === null || $sources === []) {
			$this->block($actionId, match (true) {
				$text !== null => 'knowledge_missing',
				$messageId === null => 'comment_removed',
				default => 'message_deleted',
			}, null);

			return;
		}

		try {
			$draft = $this->generator->draft(
				$text,
				$language,
				$sources,
				$messageId === null ? null : $this->conversations->history($messageId),
			);
		} catch (OpenAiFailure $e) {
			if ($e->transient && !$lastAttempt) {
				throw $e;
			}
			$this->block($actionId, 'reply_unavailable', $e);

			return;
		} catch (InvalidModelOutput $e) {
			$this->block($actionId, 'reply_invalid', $e);

			return;
		}

		if ($draft === null) {
			$this->block($actionId, 'reply_not_answerable', null);
		} elseif ($this->actions->saveDraft($actionId, $draft)) {
			$this->logger->info('Reply drafted', [
				'action_id' => $actionId,
				'knowledge' => implode(', ', array_map(fn(array $source) => "{$source['key']}@{$source['version']}", $draft->knowledge)),
			]);
		}
	}


	/**
	 * Language and knowledge keys of the classification behind the reply.
	 *
	 * @return array{?string, list<string>}
	 */
	private function request(Row $action): array
	{
		$result = $action->result_json === null ? null : Json::decode($action->result_json, forceArrays: true);
		$classification = is_array($result) && is_array($result['classification'] ?? null) ? $result['classification'] : [];
		$language = $classification['language'] ?? null;
		$keys = $classification['knowledge_required'] ?? [];

		return [
			is_string($language) ? $language : null,
			is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [],
		];
	}


	/**
	 * @param list<string> $keys
	 * @return list<array{id: int, key: string, version: int, title: string, content: string}>
	 */
	private function sources(array $keys, string $language): array
	{
		if (!in_array($language, $this->knowledge->locales(), true)) {
			return [];
		}

		return array_map(fn(Row $source) => [
			'id' => (int) $source->id,
			'key' => (string) $source->key,
			'version' => (int) $source->version,
			'title' => (string) $source->title,
			'content' => (string) $source->content,
		], $this->knowledge->current($keys, $language));
	}


	private function block(int $actionId, string $reason, ?\Throwable $error): void
	{
		$this->actions->block($actionId, [
			$reason,
		]);
		$this->logger->warning('Reply not drafted', [
			'action_id' => $actionId,
			'reason' => $reason,
			'error' => $error === null ? null : $error::class . ': ' . $error->getMessage(),
		]);
	}
}
