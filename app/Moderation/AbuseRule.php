<?php

declare(strict_types=1);

namespace App\Moderation;


/**
 * Thresholds of one kind of inappropriate content: a score at or above hideMin hides the comment,
 * at or above reviewMin sends it to review, anything lower keeps it. A null threshold is off.
 */
final readonly class AbuseRule
{
	public function __construct(
		public ?float $hideMin,
		public ?float $reviewMin,
	) {
		foreach ([$hideMin, $reviewMin] as $threshold) {
			if ($threshold !== null && ($threshold < 0.5 || $threshold > 1.0)) {
				throw new InvalidPolicy('policy.error.threshold');
			}
		}
		if ($hideMin !== null && $reviewMin !== null && $reviewMin > $hideMin) {
			throw new InvalidPolicy('policy.error.review-above-hide');
		}
	}


	public function action(float $score): Action
	{
		return match (true) {
			$this->hideMin !== null && $score >= $this->hideMin => Action::Hide,
			$this->reviewMin !== null && $score >= $this->reviewMin => Action::Review,
			default => Action::Keep,
		};
	}
}
