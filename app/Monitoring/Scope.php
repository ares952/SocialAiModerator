<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Meta\Platform;


/**
 * Part of the traffic the dashboard numbers cover: everything, one platform, or one social
 * account. Events carry the platform and the account themselves; decisions follow their event,
 * interventions the account of their comment or conversation (ActionAccount). Queue jobs belong
 * to no account and count only without a filter.
 */
final readonly class Scope
{
	/** Joins of moderation_actions `a` to its social account `s`. */
	public const ActionAccount = 'LEFT JOIN social_comments sc ON sc.id = a.comment_id
		LEFT JOIN social_messages sm ON sm.id = a.message_id
		LEFT JOIN social_conversations sv ON sv.id = sm.conversation_id
		JOIN social_accounts s ON s.id = COALESCE(sc.account_id, sv.account_id)';


	public function __construct(
		public ?Platform $platform = null,
		public ?int $accountId = null,
	) {
	}


	public function all(): bool
	{
		return $this->platform === null && $this->accountId === null;
	}


	/**
	 * @return array<string, int|string> conditions for `?and` on social_events with the alias
	 */
	public function events(string $alias): array
	{
		return $this->conditions("$alias.platform", "$alias.account_id");
	}


	/**
	 * @return array<string, int|string> conditions for `?and` on social_accounts with the alias
	 */
	public function accounts(string $alias): array
	{
		return $this->conditions("$alias.platform", "$alias.id");
	}


	/**
	 * @return array<string, int|string>
	 */
	private function conditions(string $platform, string $account): array
	{
		$conditions = [];
		if ($this->platform !== null) {
			$conditions[$platform] = $this->platform->value;
		}

		if ($this->accountId !== null) {
			$conditions[$account] = $this->accountId;
		}

		return $conditions;
	}
}
