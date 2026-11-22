<?php

declare(strict_types=1);

namespace App\Knowledge;


/**
 * Rejected knowledge source. The message is a translation key shown to the operator; an import
 * also names the field of the file (never its text).
 */
final class InvalidKnowledge extends \RuntimeException
{
	public function __construct(
		string $message,
		public readonly string $field = '',
	) {
		parent::__construct($message);
	}
}
