<?php

declare(strict_types=1);

namespace App\Meta;


/**
 * A comment as Meta returns it (GET /{comment-id}); IDs with the platform prefix. `hidden` and
 * `canHide` are null when Meta did not return the field.
 */
final readonly class CommentInfo
{
	public function __construct(
		public string $id,
		public ?string $text,
		public ?string $authorId,
		public ?\DateTimeImmutable $createdAt,
		public ?bool $hidden,
		public ?bool $canHide,
		public ?string $parentId,
	) {
	}


	/**
	 * @param array<mixed> $response
	 */
	public static function fromApi(Platform $platform, array $response): self
	{
		$string = static fn(mixed $value): ?string => is_string($value) || is_int($value) ? (string) $value : null;
		$prefixed = static fn(mixed $value): ?string => $string($value) === null ? null : $platform->externalId((string) $string($value));
		$time = $response[$platform === Platform::Facebook ? 'created_time' : 'timestamp'] ?? null;
		$parent = $platform === Platform::Facebook ? ($response['parent']['id'] ?? null) : ($response['parent_id'] ?? null);

		return new self(
			id: $prefixed($response['id'] ?? null) ?? throw new MetaFailure('Graph returned a comment without an ID.', uncertain: false, transient: false),
			text: $string($response[$platform === Platform::Facebook ? 'message' : 'text'] ?? null),
			authorId: $prefixed($response['from']['id'] ?? null),
			createdAt: is_string($time) ? (new \DateTimeImmutable($time))->setTimezone(new \DateTimeZone('UTC')) : null,
			hidden: is_bool($value = $response[$platform === Platform::Facebook ? 'is_hidden' : 'hidden'] ?? null) ? $value : null,
			canHide: is_bool($response['can_hide'] ?? null) ? $response['can_hide'] : null,
			parentId: $prefixed($parent),
		);
	}
}
