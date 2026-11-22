<?php

declare(strict_types=1);

namespace App\Meta;

use DateTimeImmutable;


/**
 * One webhook event as the application understands it. Only the metadata (columns()) is stored
 * in plain form; the customer content (text, author) exists only in memory, read from the
 * encrypted payload when it is needed.
 */
final readonly class NormalizedEvent
{
	public function __construct(
		public EventType $type,
		/** Comment ID or message `mid` with the platform prefix. */
		public ?string $externalId = null,
		/** UTC time of the change according to Meta; null when the payload has none. */
		public ?DateTimeImmutable $occurredAt = null,
		/** Why the event is unsupported; never contains customer content. */
		public ?string $problem = null,
		/** Comment or message text; untrusted customer content. */
		#[\SensitiveParameter]
		public ?string $text = null,
		/** Name or username of the author as Meta sends it (messages carry none). */
		public ?string $author = null,
		/** Facebook or Instagram link of the post the comment belongs to. */
		public ?string $permalink = null,
		/** Comment author with the platform prefix. */
		public ?string $authorId = null,
		/** The comment is written by the account itself (e.g. a reply of the Page). */
		public bool $own = false,
		/** Post or media the comment belongs to, with the platform prefix. */
		public ?string $postId = null,
		/** Comment this one replies to, with the platform prefix; null for a top-level comment. */
		public ?string $parentId = null,
		/** The customer of a message: the sender of a received message, the recipient of an echo. */
		public ?string $customerId = null,
	) {
	}


	public static function unsupported(string $problem): self
	{
		return new self(EventType::Unsupported, problem: $problem);
	}


	/**
	 * @return array{event_type: string, external_id: ?string, occurred_at: ?string, last_error: ?string}
	 */
	public function columns(): array
	{
		return [
			'event_type' => $this->type->value,
			'external_id' => $this->externalId,
			'occurred_at' => $this->occurredAt?->format('Y-m-d H:i:s.v'),
			'last_error' => $this->problem,
		];
	}
}
