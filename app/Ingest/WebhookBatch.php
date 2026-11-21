<?php

declare(strict_types=1);

namespace App\Ingest;

use App\Meta\Platform;
use Nette\Utils\Json;
use Nette\Utils\JsonException;


/**
 * One Meta webhook delivery split into single events. A delivery may carry several entries
 * (accounts) and several items per entry. Unknown fields stay in the payload and are otherwise
 * ignored; interpreting the items is the job of the worker. A delivery for an object the
 * application does not handle (e.g. `user`, `permissions`) has no platform and no events: it is
 * acknowledged, so Meta does not retry it.
 */
final class WebhookBatch
{
	/** Entry fields whose list items are single events. */
	private const ItemLists = [
		'changes',
		'messaging',
		'standby',
	];


	/**
	 * @param list<IncomingEvent> $events
	 */
	private function __construct(
		/** Webhook `object` (lowercase letters and underscores). */
		public readonly string $object,
		/** Null when the object is not supported. */
		public readonly ?Platform $platform,
		public readonly array $events,
	) {
	}


	/**
	 * @throws InvalidWebhookPayload
	 */
	public static function parse(#[\SensitiveParameter] string $body): self
	{
		try {
			$data = Json::decode($body, forceArrays: true);
		} catch (JsonException $e) {
			throw new InvalidWebhookPayload('Body is not valid JSON: ' . $e->getMessage());
		}

		if (
			!is_array($data)
			|| !is_string($data['object'] ?? null)
			|| !preg_match('~^[a-z_]{1,64}$~D', $data['object'])
			|| !is_array($data['entry'] ?? null)
			|| !array_is_list($data['entry'])
		) {
			throw new InvalidWebhookPayload('Body must contain `object` and an `entry` list.');
		}

		$object = $data['object'];
		$platform = Platform::fromWebhookObject($object);
		if ($platform === null) {
			return new self($object, null, []);
		}

		$events = [];
		foreach ($data['entry'] as $entry) {
			array_push($events, ...self::entryEvents($platform, $object, $entry));
		}

		return new self($object, $platform, $events);
	}


	/**
	 * @return list<IncomingEvent>
	 */
	private static function entryEvents(Platform $platform, string $object, mixed $entry): array
	{
		if (
			!is_array($entry)
			|| !(is_string($entry['id'] ?? null) || is_int($entry['id'] ?? null))
		) {
			throw new InvalidWebhookPayload('Entry must be an object with an `id`.');
		}

		$events = [];
		foreach (self::ItemLists as $list) {
			$items = $entry[$list] ?? [];
			if (!is_array($items) || !array_is_list($items)) {
				throw new InvalidWebhookPayload("Entry field `$list` must be a list.");
			}

			foreach ($items as $item) {
				if (!is_array($item)) {
					throw new InvalidWebhookPayload("Items of `$list` must be objects.");
				}

				$topic = $list === 'changes' ? $item['field'] ?? null : $list;
				if (!is_string($topic) || $topic === '') {
					throw new InvalidWebhookPayload('Change item must have a `field`.');
				}

				$events[] = new IncomingEvent(
					platform: $platform,
					accountExternalId: $platform->externalId((string) $entry['id']),
					topic: $topic,
					eventKey: self::eventKey($list, $item),
					payload: Json::encode([
						'object' => $object,
						'entry_id' => (string) $entry['id'],
						'entry_time' => $entry['time'] ?? null,
						'list' => $list,
						'item' => $item,
					]),
				);
			}
		}

		return $events;
	}


	/**
	 * Messages carry a stable `mid`. An unsent (deleted) message reuses the `mid` of the original
	 * message, so `is_deleted` is part of the key; otherwise the deletion would be dropped as
	 * a duplicate. `is_echo` is part of it too, so an own message never collides with a customer's
	 * message. Other items (comment changes, reads, reactions, edits) have no event ID, so
	 * the key is the hash of the normalized item. The delivery time is left out on purpose:
	 * a redelivery must map to the same key even if Meta batches it differently.
	 *
	 * @param array<mixed> $item
	 */
	private static function eventKey(string $list, array $item): string
	{
		$message = $item['message'] ?? null;
		$mid = is_array($message) ? $message['mid'] ?? null : null;
		if ($list !== 'changes' && is_string($mid) && $mid !== '') {
			$identity = "$list:message:$mid";
			foreach (['is_echo', 'is_deleted'] as $flag) {
				if (($message[$flag] ?? false) === true) {
					$identity .= ":$flag";
				}
			}
		} else {
			$identity = "$list:" . Json::encode(self::normalize($item));
		}

		return hash('sha256', $identity);
	}


	/**
	 * Object keys sorted recursively, list order kept.
	 */
	private static function normalize(mixed $value): mixed
	{
		if (!is_array($value)) {
			return $value;
		}

		$value = array_map(self::normalize(...), $value);
		if (!array_is_list($value)) {
			ksort($value, SORT_STRING);
		}

		return $value;
	}
}
