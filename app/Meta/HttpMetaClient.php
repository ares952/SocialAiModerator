<?php

declare(strict_types=1);

namespace App\Meta;

use App\Account\AccessToken;
use Nette\Utils\Json;


/**
 * Meta writes over the Graph API (docs/meta-checklist.md §8). Called only by the ActionDispatcher
 * for an outbox record it has just re-validated. Messages use the standard window
 * (messaging_type RESPONSE), never the HUMAN_AGENT tag.
 */
final class HttpMetaClient implements MetaClient
{
	public function __construct(
		private readonly GraphApi $api,
	) {
	}


	public function hideComment(Platform $platform, AccessToken $token, string $commentId, bool $hidden): void
	{
		[$path, $fields] = self::request('hide', $platform, $commentId, hidden: $hidden);
		$response = $this->api->post($path, $fields, $token->value);
		if (($response['success'] ?? null) !== true) {
			throw new MetaFailure("Graph POST /{id} did not confirm the change.", uncertain: false, transient: false, httpStatus: 200);
		}
	}


	public function replyComment(Platform $platform, AccessToken $token, string $commentId, #[\SensitiveParameter] string $text): string
	{
		[$path, $fields] = self::request('reply', $platform, $commentId, text: $text);

		return self::createdId($platform, $this->api->post($path, $fields, $token->value), 'id');
	}


	public function sendMessage(
		Platform $platform,
		AccessToken $token,
		string $senderId,
		string $recipientId,
		#[\SensitiveParameter]
		string $text,
		string $metadata,
	): string {
		[$path, $fields] = self::request('message', $platform, $senderId, recipient: $recipientId, text: $text, metadata: $metadata);

		return self::createdId($platform, $this->api->post($path, $fields, $token->value), 'message_id');
	}


	/**
	 * Path and form fields of a write; IDs come with the platform prefix and are checked to be
	 * Meta IDs of that platform.
	 *
	 * @return array{string, array<string, string>}
	 */
	public static function request(
		string $operation,
		Platform $platform,
		string $objectId,
		?bool $hidden = null,
		?string $recipient = null,
		#[\SensitiveParameter]
		?string $text = null,
		?string $metadata = null,
	): array {
		$id = self::id($platform, $objectId);

		return match ($operation) {
			'hide' => [
				"/$id",
				$platform === Platform::Facebook
					? ['is_hidden' => $hidden === true ? 'true' : 'false']
					: ['hide' => $hidden === true ? 'true' : 'false'],
			],
			'reply' => [
				$platform === Platform::Facebook ? "/$id/comments" : "/$id/replies",
				['message' => (string) $text],
			],
			'message' => [
				"/$id/messages",
				[
					'recipient' => Json::encode(['id' => self::id($platform, (string) $recipient)]),
					'messaging_type' => 'RESPONSE',
					'message' => Json::encode([
						'text' => (string) $text,
						'metadata' => (string) $metadata,
					]),
				],
			],
			default => throw new \LogicException("Unknown write $operation."),
		};
	}


	private static function id(Platform $platform, string $prefixed): string
	{
		$prefix = $platform->externalId('');
		$id = str_starts_with($prefixed, $prefix) ? substr($prefixed, strlen($prefix)) : '';
		if (preg_match('~^[0-9_]+$~D', $id) !== 1) {
			throw new \InvalidArgumentException("Expected a $platform->value ID with the $prefix prefix.");
		}

		return $id;
	}


	/**
	 * @param array<mixed> $response
	 */
	private static function createdId(Platform $platform, array $response, string $field): string
	{
		$id = $response[$field] ?? null;
		if (!is_string($id) || $id === '') {
			// Meta answered 200 but without the ID: the write probably happened.
			throw new MetaFailure('Graph POST returned no ID of the created object.', uncertain: true, transient: false, httpStatus: 200);
		}

		return $platform->externalId($id);
	}
}
