<?php

declare(strict_types=1);

namespace App\Meta;

use DateTimeImmutable;


/**
 * Metadata of one webhook event. Customer content (texts, names) stays in the encrypted payload.
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
