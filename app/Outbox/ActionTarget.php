<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Comment\Visibility;


/**
 * Current state of the comment an action targets, read right before sending.
 */
final readonly class ActionTarget
{
	/**
	 * @param bool $changed the text changed after the action was created
	 * @param bool $replied another reply to the comment was sent or may have been sent
	 */
	public function __construct(
		public Visibility $visibility,
		public bool $own,
		public bool $changed,
		public bool $replied = false,
	) {
	}
}
