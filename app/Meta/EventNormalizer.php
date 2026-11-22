<?php

declare(strict_types=1);

namespace App\Meta;

use DateTimeImmutable;
use Nette\Utils\Json;


/**
 * Maps one stored webhook event (WebhookBatch payload: object, entry_id, entry_time, list, item)
 * to its type, external ID and time, and reads the customer content: text, author, post, parent
 * comment and the customer of a conversation. Facebook `feed` and Instagram `comments` have
 * different structures and are mapped separately; Messenger and Instagram messaging share the Messenger
 * Platform format. Anything unknown becomes Unsupported with a reason, so a human sees it.
 */
final class EventNormalizer
{
	private const FeedCommentVerbs = [
		'add' => EventType::CommentCreated,
		'edited' => EventType::CommentEdited,
		'remove' => EventType::CommentRemoved,
		'hide' => EventType::CommentHidden,
		'unhide' => EventType::CommentUnhidden,
	];

	/** `feed` items that are reactions to a post or comment. */
	private const FeedReactions = [
		'reaction',
		'like',
	];

	/** Messaging item fields without customer content to moderate. */
	private const MessagingOther = [
		'read',
		'delivery',
		'reaction',
	];

	/**
	 * Fields that carry a messaging item in `value` of a change. Meta sends this format for the
	 * test button in the App Dashboard, with the timestamp in seconds.
	 */
	private const MessagingFields = [
		'messages',
		'message_echoes',
		'messaging_postbacks',
	];


	public function normalize(#[\SensitiveParameter] string $payload): NormalizedEvent
	{
		$data = Json::decode($payload, forceArrays: true);
		$platform = Platform::fromWebhookObject((string) ($data['object'] ?? ''))
			?? throw new \LogicException('Stored event has an unsupported webhook object.');
		$item = $data['item'] ?? null;
		if (!is_array($item)) {
			throw new \LogicException('Stored event has no item.');
		}

		return match ($data['list'] ?? null) {
			'messaging' => $this->message($platform, $item, inMilliseconds: true),
			'changes' => $this->change($platform, $item, $data),
			'standby' => NormalizedEvent::unsupported('standby (handover protocol) is not supported'),
			default => throw new \LogicException('Stored event has an unknown list.'),
		};
	}


	/**
	 * @param array<mixed> $change
	 * @param array<mixed> $data
	 */
	private function change(Platform $platform, array $change, array $data): NormalizedEvent
	{
		$field = $change['field'] ?? null;
		$value = $change['value'] ?? null;
		if (!is_array($value)) {
			return NormalizedEvent::unsupported('change `' . self::token($field) . '` has no value');
		}

		return match (true) {
			in_array($field, self::MessagingFields, true) => $this->message($platform, $value, inMilliseconds: false),
			$platform === Platform::Facebook && $field === 'feed' => $this->feed($value, $data['entry_id'] ?? null),
			$platform === Platform::Instagram && $field === 'comments' => $this->instagramComment($value, $data['entry_id'] ?? null, $data['entry_time'] ?? null),
			default => NormalizedEvent::unsupported('change `' . self::token($field) . '` is not supported'),
		};
	}


	/**
	 * Facebook Page `feed`: comments carry `verb`, `comment_id` and `created_time` in seconds.
	 *
	 * @param array<mixed> $value
	 */
	private function feed(array $value, mixed $pageId): NormalizedEvent
	{
		$item = $value['item'] ?? null;
		$occurredAt = self::fromSeconds($value['created_time'] ?? null);
		if (in_array($item, self::FeedReactions, true)) {
			return new NormalizedEvent(EventType::Other, occurredAt: $occurredAt);
		}

		if ($item !== 'comment') {
			// The Page's own posts; a visitor post may need moderation, so it is not dismissed.
			$own = is_array($value['from'] ?? null) && self::id($value['from']['id'] ?? null) === self::id($pageId);

			return $own
				? new NormalizedEvent(EventType::Other, occurredAt: $occurredAt)
				: NormalizedEvent::unsupported('feed item `' . self::token($item) . '` is not supported');
		}

		$type = self::FeedCommentVerbs[$value['verb'] ?? ''] ?? null;
		if ($type === null) {
			return NormalizedEvent::unsupported('feed comment verb `' . self::token($value['verb'] ?? null) . '` is not supported');
		}

		$commentId = self::id($value['comment_id'] ?? null);
		if ($commentId === null) {
			return NormalizedEvent::unsupported('feed comment has no comment_id');
		}

		$post = $value['post'] ?? null;
		$from = is_array($value['from'] ?? null) ? $value['from'] : [];
		$authorId = self::id($from['id'] ?? null);
		$postId = self::id($value['post_id'] ?? null);
		$parentId = self::id($value['parent_id'] ?? null);

		return new NormalizedEvent(
			$type,
			Platform::Facebook->externalId($commentId),
			$occurredAt,
			text: self::text($value['message'] ?? null),
			author: self::text($from['name'] ?? null),
			permalink: self::permalink(is_array($post) ? $post['permalink_url'] ?? null : null),
			authorId: self::prefixed(Platform::Facebook, $authorId),
			own: $authorId !== null && $authorId === self::id($pageId),
			postId: self::prefixed(Platform::Facebook, $postId),
			// A top-level comment has the post as its parent.
			parentId: $parentId === $postId ? null : self::prefixed(Platform::Facebook, $parentId),
		);
	}


	/**
	 * Instagram `comments`: only new comments are delivered; the value has no time, so the time of
	 * the entry (seconds) is used.
	 *
	 * @param array<mixed> $value
	 */
	private function instagramComment(array $value, mixed $accountId, mixed $entryTime): NormalizedEvent
	{
		$commentId = self::id($value['id'] ?? null);
		if ($commentId === null) {
			return NormalizedEvent::unsupported('comment has no id');
		}

		$from = is_array($value['from'] ?? null) ? $value['from'] : [];
		$authorId = self::id($from['id'] ?? null);

		return new NormalizedEvent(
			EventType::CommentCreated,
			Platform::Instagram->externalId($commentId),
			self::fromSeconds($entryTime),
			text: self::text($value['text'] ?? null),
			author: self::text($from['username'] ?? null),
			authorId: self::prefixed(Platform::Instagram, $authorId),
			own: $authorId !== null && $authorId === self::id($accountId),
			postId: self::prefixed(Platform::Instagram, self::id(is_array($value['media'] ?? null) ? $value['media']['id'] ?? null : null)),
			parentId: self::prefixed(Platform::Instagram, self::id($value['parent_id'] ?? null)),
		);
	}


	/**
	 * Messenger Platform item (sender, recipient, timestamp and one content field).
	 *
	 * @param array<mixed> $item
	 */
	private function message(Platform $platform, array $item, bool $inMilliseconds): NormalizedEvent
	{
		$timestamp = $item['timestamp'] ?? null;
		$occurredAt = $inMilliseconds ? self::fromMilliseconds($timestamp) : self::fromSeconds($timestamp);

		$message = $item['message'] ?? null;
		if (is_array($message)) {
			$mid = self::id($message['mid'] ?? null);
			if ($mid === null) {
				return NormalizedEvent::unsupported('message has no mid');
			}

			$type = match (true) {
				($message['is_deleted'] ?? false) === true => EventType::MessageDeleted,
				($message['is_echo'] ?? false) === true => EventType::MessageEcho,
				default => EventType::MessageReceived,
			};
			$customer = $type === EventType::MessageEcho ? $item['recipient'] ?? null : $item['sender'] ?? null;

			return new NormalizedEvent(
				$type,
				$platform->externalId($mid),
				$occurredAt,
				text: $type === EventType::MessageDeleted ? null : self::text($message['text'] ?? null),
				customerId: self::prefixed($platform, self::id(is_array($customer) ? $customer['id'] ?? null : null)),
			);
		}

		$postback = $item['postback'] ?? null;
		if (is_array($postback)) {
			$mid = self::id($postback['mid'] ?? null);

			return new NormalizedEvent(EventType::Postback, $mid === null ? null : $platform->externalId($mid), $occurredAt);
		}

		foreach (self::MessagingOther as $field) {
			if (isset($item[$field])) {
				return new NormalizedEvent(EventType::Other, occurredAt: $occurredAt);
			}
		}

		$fields = array_diff(array_map(self::token(...), array_keys($item)), ['sender', 'recipient', 'timestamp']);

		return NormalizedEvent::unsupported('messaging item `' . implode(',', $fields) . '` is not supported');
	}


	/**
	 * Customer text as Meta sends it; empty means none.
	 */
	private static function text(mixed $value): ?string
	{
		return is_string($value) && trim($value) !== '' ? $value : null;
	}


	/**
	 * Link to the post on Facebook or Instagram; any other address is dropped, so the
	 * administration never links to a site chosen by the payload.
	 */
	private static function permalink(mixed $value): ?string
	{
		return is_string($value) && preg_match('~^https://(?:www\.)?(?:facebook|instagram)\.com/[\x21-\x7E]*$~D', $value)
			? $value
			: null;
	}


	/**
	 * External ID as a string; Meta sends most IDs as strings, some as numbers.
	 */
	private static function id(mixed $value): ?string
	{
		if (is_int($value) && $value > 0) {
			return (string) $value;
		}

		return is_string($value) && preg_match('~^[\x21-\x7E]{1,250}$~D', $value) ? $value : null;
	}


	private static function prefixed(Platform $platform, ?string $id): ?string
	{
		return $id === null ? null : $platform->externalId($id);
	}


	private static function fromSeconds(mixed $value): ?DateTimeImmutable
	{
		$seconds = self::integer($value);

		return $seconds === null ? null : new DateTimeImmutable("@$seconds");
	}


	private static function fromMilliseconds(mixed $value): ?DateTimeImmutable
	{
		$milliseconds = self::integer($value);
		if ($milliseconds === null) {
			return null;
		}

		return DateTimeImmutable::createFromFormat(
			'U.v',
			sprintf('%d.%03d', intdiv($milliseconds, 1000), $milliseconds % 1000),
		) ?: null;
	}


	private static function integer(mixed $value): ?int
	{
		if (is_string($value) && preg_match('~^[1-9][0-9]{0,15}$~D', $value)) {
			return (int) $value;
		}

		return is_int($value) && $value > 0 ? $value : null;
	}


	/**
	 * A field name or verb from the payload for a log or error message; anything that does not
	 * look like a Meta identifier is masked, so customer content never ends up there.
	 */
	private static function token(mixed $value): string
	{
		return is_string($value) && preg_match('~^[a-z_]{1,40}$~D', $value) ? $value : '?';
	}
}
