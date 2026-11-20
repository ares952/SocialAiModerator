<?php

declare(strict_types=1);

namespace App\Moderation;


/**
 * Result of the PolicyEngine. `automatic` says whether the policy allows the action without a
 * human; `reasons` are stable codes explaining the decision (translated as decision.reason.<code>).
 */
final readonly class Decision
{
	/**
	 * @param list<string> $reasons
	 */
	public function __construct(
		public Action $action,
		public bool $automatic,
		public array $reasons,
		public int $policyVersion,
	) {
	}
}
