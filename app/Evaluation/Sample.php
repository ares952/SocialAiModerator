<?php

declare(strict_types=1);

namespace App\Evaluation;

use App\Meta\Platform;
use App\Moderation\Action;
use App\Moderation\Category;
use App\Moderation\ContentKind;


/**
 * A hand-labelled example (synthetic or anonymized) with the expected outcome.
 */
final readonly class Sample
{
	public function __construct(
		public Platform $platform,
		public ContentKind $kind,
		public string $language,
		public string $text,
		public Category $expectedCategory,
		public Action $expectedAction,
		public ?string $note = null,
	) {
		if (trim($text) === '') {
			throw new InvalidEvaluation('form.error.required');
		}
		if (!preg_match('~^[a-z]{2}$~', $language)) {
			throw new InvalidEvaluation('evaluation.error.language');
		}
	}
}
