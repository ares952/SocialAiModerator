<?php

declare(strict_types=1);

namespace App\Moderation;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Ingest\EventStatus;
use App\Meta\EventType;
use App\Outbox\ActionStatus;
use Nette\Database\Explorer;
use Nette\Database\Row;


/**
 * Queue of comments and messages that need a human (specification §4.3): the latest AI decision
 * hands the event over (review, handoff), there is no decision yet (fail closed), or an
 * intervention waits for approval. An event leaves the queue when an operator resolves it, or when
 * its last waiting intervention is approved or rejected and the AI did not ask for a human. Events
 * can be handed over to a colleague. Lists show metadata and the AI summary, never content.
 */
final class ReviewQueue
{
	/** Open events: the condition on social_events `e`. */
	private const Open = 'e.status = ? AND e.resolved_at IS NULL AND e.event_type IN (?) AND (
		EXISTS (SELECT 1 FROM moderation_actions x WHERE x.event_id = e.id AND x.status = ?)
		OR NOT EXISTS (SELECT 1 FROM moderation_decisions n WHERE n.event_id = e.id)
		OR (SELECT d.action FROM moderation_decisions d WHERE d.event_id = e.id ORDER BY d.id DESC LIMIT 1) IN (?)
	)';


	public function __construct(
		private readonly Explorer $database,
		private readonly AuditLog $audit,
	) {
	}


	public function count(?ReviewFilter $filter, ?int $operatorId): int
	{
		return (int) $this->database->query(
			'SELECT COUNT(*) FROM social_events e WHERE ' . self::Open . ' AND ?and',
			...$this->openArguments(),
			...[
				self::filter($filter, $operatorId),
			],
		)->fetchField();
	}


	/**
	 * Oldest first, with the account, the latest decision and the number of waiting interventions.
	 *
	 * @return list<Row>
	 */
	public function page(?ReviewFilter $filter, ?int $operatorId, int $limit, int $offset): array
	{
		return $this->database->query(
			'SELECT e.id, e.received_at, e.platform, e.event_type, a.name AS account_name,
				e.assignee_id, o.name AS assignee_name,
				d.action AS decision_action, d.category, d.confidence, JSON_EXTRACT(d.result_json, \'$.reasons\') AS reasons_json,
				(SELECT COUNT(*) FROM moderation_actions x WHERE x.event_id = e.id AND x.status = ?) AS pending
			FROM social_events e
			LEFT JOIN social_accounts a ON a.id = e.account_id
			LEFT JOIN operators o ON o.id = e.assignee_id
			LEFT JOIN moderation_decisions d ON d.id = (SELECT MAX(m.id) FROM moderation_decisions m WHERE m.event_id = e.id)
			WHERE ' . self::Open . ' AND ?and
			ORDER BY e.id
			LIMIT ? OFFSET ?',
			ActionStatus::Pending->value,
			...$this->openArguments(),
			...[
				self::filter($filter, $operatorId),
				$limit,
				$offset,
			],
		)->fetchAll();
	}


	/**
	 * The oldest open event after the given one, else the oldest open event other than it.
	 */
	public function next(int $eventId): ?int
	{
		$next = $this->database->query(
			'SELECT e.id FROM social_events e WHERE ' . self::Open . ' AND e.id <> ? ORDER BY e.id > ? DESC, e.id LIMIT 1',
			...$this->openArguments(),
			...[
				$eventId,
				$eventId,
			],
		)->fetchField();

		return $next === null ? null : (int) $next;
	}


	public function isOpen(int $eventId): bool
	{
		return $this->database->query(
			'SELECT 1 FROM social_events e WHERE ' . self::Open . ' AND e.id = ?',
			...$this->openArguments(),
			...[
				$eventId,
			],
		)->fetchField() !== null;
	}


	public function resolve(int $eventId, int $operatorId): void
	{
		$changed = $this->database->query(
			'UPDATE social_events SET resolved_at = NOW(3), resolved_by = ? WHERE id = ? AND resolved_at IS NULL',
			$operatorId,
			$eventId,
		)->getRowCount();
		if ($changed > 0) {
			$this->audit->write($operatorId, AuditAction::EventResolve, 'event', $eventId);
		}
	}


	public function reopen(int $eventId, int $operatorId): void
	{
		$changed = $this->database->query(
			'UPDATE social_events SET resolved_at = NULL, resolved_by = NULL WHERE id = ? AND resolved_at IS NOT NULL',
			$eventId,
		)->getRowCount();
		if ($changed > 0) {
			$this->audit->write($operatorId, AuditAction::EventReopen, 'event', $eventId);
		}
	}


	/**
	 * Hands the event over to an active operator, or takes the handover back (null).
	 *
	 * @throws \InvalidArgumentException the operator is unknown or inactive
	 */
	public function assign(int $eventId, ?int $assigneeId, int $operatorId): void
	{
		if ($assigneeId !== null && $this->database->query('SELECT 1 FROM operators WHERE id = ? AND active = 1', $assigneeId)->fetchField() === null) {
			throw new \InvalidArgumentException("Operator $assigneeId cannot take over events.");
		}

		$changed = $this->database->query('UPDATE social_events SET assignee_id = ? WHERE id = ? AND NOT (assignee_id <=> ?)', $assigneeId, $eventId, $assigneeId)
			->getRowCount();
		if ($changed > 0) {
			$this->audit->write($operatorId, AuditAction::EventAssign, 'event', $eventId, 'assignee=' . ($assigneeId ?? 'none'));
		}
	}


	/**
	 * @return list<mixed> values of the placeholders in Open
	 */
	private function openArguments(): array
	{
		return [
			EventStatus::Review->value,
			array_values(array_map(
				fn(EventType $type): string => $type->value,
				array_filter(EventType::cases(), fn(EventType $type): bool => $type->contentKind() !== null),
			)),
			ActionStatus::Pending->value,
			[
				Action::Review->value,
				Action::Handoff->value,
			],
		];
	}


	/**
	 * @return array<string, int|null>
	 */
	private static function filter(?ReviewFilter $filter, ?int $operatorId): array
	{
		return match ($filter) {
			null => [],
			ReviewFilter::Mine => [
				'e.assignee_id' => $operatorId,
			],
			ReviewFilter::Unassigned => [
				'e.assignee_id' => null,
			],
		};
	}
}
