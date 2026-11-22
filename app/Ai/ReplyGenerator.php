<?php

declare(strict_types=1);

namespace App\Ai;

use Nette\Utils\FileSystem;
use Nette\Utils\Json;


/**
 * Writes a reply suggestion to a comment only from published knowledge (specification §5.3). The
 * comment travels redacted and as a JSON value, separated from the versioned instructions. The
 * output is untrusted: besides the strict schema, every link, e-mail address and number of the
 * reply must appear in the knowledge the reply relies on, so an invented price, discount or
 * contact never reaches a moderator as a ready answer. A human approves every reply.
 */
final class ReplyGenerator
{
	public const PromptVersion = 'reply-v1';
	public const MaxLength = 1000;
	private const SchemaName = 'reply';


	public function __construct(
		private readonly OpenAiClient $client,
		private readonly string $model,
	) {
	}


	/**
	 * @param list<array{id: int, key: string, version: int, title: string, content: string}> $knowledge
	 *        current published sources in the reply language
	 * @return ReplyDraft|null null when the knowledge does not answer the comment
	 * @throws OpenAiFailure
	 * @throws InvalidModelOutput
	 */
	public function draft(#[\SensitiveParameter] string $comment, string $language, array $knowledge): ?ReplyDraft
	{
		if ($this->model === '') {
			throw new OpenAiFailure('OPENAI_REPLY_MODEL is not set.', transient: false);
		}
		if ($knowledge === []) {
			throw new \LogicException('A reply needs knowledge.');
		}

		$response = $this->client->structured(
			$this->model,
			FileSystem::read(__DIR__ . '/prompts/' . self::PromptVersion . '.md'),
			Json::encode([
				'language' => $language,
				'comment' => Redactor::redact($comment),
				'knowledge' => array_map(fn(array $source) => [
					'key' => $source['key'],
					'title' => $source['title'],
					'content' => $source['content'],
				], $knowledge),
			]),
			self::SchemaName,
			self::schema(),
		);

		$output = $response->output;
		$keys = array_keys($output);
		sort($keys);
		if ($keys !== ['can_answer', 'reason', 'reply', 'used_knowledge']) {
			throw new InvalidModelOutput('Unexpected fields in the reply.');
		}
		if (!is_bool($output['can_answer']) || !is_string($output['reply']) || !is_string($output['reason'])) {
			throw new InvalidModelOutput('Invalid reply fields.');
		}
		if (!$output['can_answer']) {
			return null;
		}

		$text = trim($output['reply']);
		if ($text === '' || mb_strlen($text) > self::MaxLength) {
			throw new InvalidModelOutput('Invalid reply length.');
		}
		$used = self::used($output['used_knowledge'], $knowledge);
		self::checkFacts($text, implode("\n", array_map(fn(array $source) => $source['title'] . "\n" . $source['content'], $used)));

		return new ReplyDraft(
			text: $text,
			knowledge: array_map(fn(array $source) => [
				'id' => $source['id'],
				'key' => $source['key'],
				'version' => $source['version'],
			], $used),
			model: $response->model !== '' ? $response->model : $this->model,
			promptVersion: self::PromptVersion,
		);
	}


	/**
	 * The offered sources the model names; an unknown key or none at all is invalid.
	 *
	 * @param list<array{id: int, key: string, version: int, title: string, content: string}> $knowledge
	 * @return non-empty-list<array{id: int, key: string, version: int, title: string, content: string}>
	 * @throws InvalidModelOutput
	 */
	private static function used(mixed $keys, array $knowledge): array
	{
		if (!is_array($keys) || !array_is_list($keys) || $keys === []) {
			throw new InvalidModelOutput('Invalid used_knowledge.');
		}

		$used = [];
		foreach ($knowledge as $source) {
			if (in_array($source['key'], $keys, true)) {
				$used[] = $source;
			}
		}
		if (count($used) !== count(array_unique($keys, SORT_REGULAR))) {
			throw new InvalidModelOutput('The reply relies on knowledge that was not offered.');
		}

		return $used;
	}


	/**
	 * Every link, e-mail address and number of the reply must appear in the used knowledge. A space
	 * before a group of three digits is a thousands separator, so "1 100" matches "1100"; numbers
	 * match whole, so "10" does not match "100".
	 *
	 * @throws InvalidModelOutput
	 */
	private static function checkFacts(string $text, string $source): void
	{
		$normalize = static fn(string $value): string => mb_strtolower(
			preg_replace('~(?<=\d)[\s\x{00A0}\x{202F}](?=\d{3}(?!\d))~u', '', $value) ?? '',
		);
		$source = $normalize($source);

		preg_match_all(
			'~[\w.+-]+@[\w-]+(?:\.[\w-]+)+|(?:https?://|www\.)[^\s<>"\']*[^\s<>"\'.,;:!?)]|\b[\w-]+(?:\.[\w-]+)*\.[a-z]{2,}\b|\d(?:[\d.,:]*\d)?~iu',
			$normalize($text),
			$matches,
		);
		foreach ($matches[0] as $fact) {
			if (!preg_match('~(?<![\w.-])' . preg_quote($fact, '~') . '(?![\w@-]|[.,:]\d)~u', $source)) {
				throw new InvalidModelOutput('The reply contains a fact that is not in the knowledge.');
			}
		}
	}


	/**
	 * @return array<string, mixed>
	 */
	private static function schema(): array
	{
		$schema = Json::decode(FileSystem::read(__DIR__ . '/schemas/reply-v1.json'), forceArrays: true);
		if (!is_array($schema)) {
			throw new \LogicException('Invalid reply schema.');
		}

		return $schema;
	}
}
