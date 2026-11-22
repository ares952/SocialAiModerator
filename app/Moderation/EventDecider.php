<?php

declare(strict_types=1);

namespace App\Moderation;

use App\Account\AccountStatus;
use App\Ai\Classifier;
use App\Ai\InvalidModelOutput;
use App\Ai\OpenAiFailure;
use App\Comment\Comments;
use App\Conversation\Conversations;
use App\Ingest\EventStatus;
use App\Ingest\EventStore;
use App\Meta\NormalizedEvent;
use App\Meta\Platform;
use App\Outbox\Actions;
use App\Queue\JobQueue;
use App\Queue\JobType;
use Nette\Database\Explorer;


/**
 * Worker step for one comment or message (job event.decide, specification §5.1): classify it,
 * let the PolicyEngine decide with the active policy, the panic switch and the account state,
 * and store the decision. A hide of a comment becomes a moderation action (Actions::propose());
 * nothing is sent here, the ActionDispatcher checks every action again before sending. A failed classification is stored as a fail-closed review with the error.
 * The repeated-text rule counts earlier identical texts of the same comment author or customer.
 * An event that already has a classified decision is skipped, so a repeated job does not call
 * the model again; after a failure the decision can be requested again (request()).
 */
final class EventDecider
{
	public function __construct(
		private readonly Explorer $database,
		private readonly EventStore $events,
		private readonly Classifier $classifier,
		private readonly Policies $policies,
		private readonly PolicyEngine $engine,
		private readonly AutomationSwitch $switch,
		private readonly Decisions $decisions,
		private readonly JobQueue $queue,
		private readonly Comments $comments,
		private readonly Conversations $conversations,
		private readonly Actions $actions,
	) {
	}


	/**
	 * Queues the decision of an event in review that has none yet (an older event, or after
	 * a failure); false when there is nothing to decide.
	 */
	public function request(int $eventId): bool
	{
		$status = $this->database->query('SELECT status FROM social_events WHERE id = ?', $eventId)->fetchField();
		$normalized = $status === EventStatus::Review->value ? $this->events->normalized($eventId) : null;
		if (
			$normalized === null
			|| $normalized->type->contentKind() === null
			|| $normalized->text === null
			|| $this->decisions->classified($eventId)
		) {
			return false;
		}

		$this->queue->push(JobType::DecideEvent, $eventId);

		return true;
	}


	/**
	 * @param bool $lastAttempt a transient AI failure is retried by the queue until the last attempt
	 * @throws OpenAiFailure transient failure before the last attempt
	 */
	public function decide(int $eventId, bool $lastAttempt): void
	{
		if ($this->decisions->classified($eventId)) {
			return;
		}

		$event = $this->database->query(
			'SELECT e.platform, e.account_id, a.status AS account_status
			FROM social_events e
			LEFT JOIN social_accounts a ON a.id = e.account_id
			WHERE e.id = ?',
			$eventId,
		)->fetch();
		$normalized = $event === null ? null : $this->events->normalized($eventId);
		$kind = $normalized?->type->contentKind();
		if ($event === null || $normalized === null || $kind === null || $normalized->text === null) {
			return;
		}

		$policy = $this->policies->active();
		$content = new CustomerContent(
			Platform::from($event->platform),
			$kind,
			$normalized->text,
			$this->repeats((int) $event->account_id, $normalized, $kind, $policy->settings->repeatWindowHours),
		);
		$classified = null;
		$error = null;
		try {
			$classified = $this->classifier->classify($content);
		} catch (OpenAiFailure $e) {
			if ($e->transient && !$lastAttempt) {
				throw $e;
			}
			$error = $e::class . ': ' . $e->getMessage();
		} catch (InvalidModelOutput $e) {
			$error = $e::class . ': ' . $e->getMessage();
		}

		$decision = $this->engine->decide(
			$classified?->classification,
			$content,
			$classified !== null && $classified->moderationFlagged,
			$policy,
			new AutomationContext(
				paused: $this->switch->isPaused($event->account_id),
				accountActive: $event->account_status === AccountStatus::Active->value,
			),
		);
		$this->database->transaction(function () use ($eventId, $event, $normalized, $kind, $classified, $decision, $error): void {
			$decisionId = $this->decisions->record(
				$eventId,
				$classified,
				$decision,
				$this->classifier->model(),
				Classifier::PromptVersion,
				$error,
			);
			$comment = $kind === ContentKind::Comment && $normalized->externalId !== null
				? $this->comments->find((int) $event->account_id, $normalized->externalId)
				: null;
			if ($comment !== null) {
				$this->actions->propose($eventId, $decisionId, $comment->id, $comment->external_id, $decision);
			}
		});
	}


	private function repeats(int $accountId, NormalizedEvent $event, ContentKind $kind, int $hours): int
	{
		$personId = $kind === ContentKind::Comment ? $event->authorId : $event->customerId;
		if ($personId === null || $event->externalId === null || $event->text === null) {
			return 0;
		}

		$earlier = $kind === ContentKind::Comment
			? $this->comments->recentTexts($accountId, $personId, $event->externalId, $hours)
			: $this->conversations->recentTexts($accountId, $personId, $event->externalId, $hours);

		return ContentRules::repeats($event->text, $earlier);
	}
}
