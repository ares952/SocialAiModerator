<?php

declare(strict_types=1);

namespace App\Moderation;


/**
 * Deterministic decision about a classified comment or message (specification §5.4). The model's
 * output is untrusted advice: it can make a decision more cautious, never less. Rules:
 *
 * - no classification (model error, invalid output) => review (fail closed);
 * - complaints, billing, refunds, health, legal, or the model asking for a human => handoff;
 * - legitimate criticism and praise are kept, whatever the model recommends;
 * - spam/scam is hidden only above both hide thresholds, otherwise review; a private message
 *   cannot be hidden, so it goes to review;
 * - abuse and unknown => review;
 * - FAQ is answered only above the reply threshold and in a supported language;
 * - the action runs without a human only in `automatic` mode, without a pause, for an active
 *   account and when the policy explicitly allows that action.
 */
final class PolicyEngine
{
	public function decide(
		?Classification $classification,
		ContentKind $kind,
		bool $moderationFlagged,
		Policy $policy,
		AutomationContext $context,
	): Decision {
		$reasons = [];
		$action = $classification === null
			? $this->fail($reasons)
			: $this->action($classification, $moderationFlagged, $policy->settings, $reasons);
		if ($action === Action::Hide && $kind === ContentKind::Message) {
			$reasons[] = 'message_not_hideable';
			$action = Action::Review;
		}

		$automatic = $action->isExternal()
			&& $classification !== null
			&& $this->allowsAutomatic($action, $classification, $policy, $context, $reasons);

		return new Decision($action, $automatic, $reasons, $policy->version);
	}


	/**
	 * @param list<string> $reasons
	 */
	private function fail(array &$reasons): Action
	{
		$reasons[] = 'classification_unavailable';

		return Action::Review;
	}


	/**
	 * @param list<string> $reasons
	 */
	private function action(
		Classification $classification,
		bool $moderationFlagged,
		PolicySettings $settings,
		array &$reasons,
	): Action {
		$category = $classification->category;
		if ($category->requiresHandoff()) {
			$reasons[] = 'handoff_category';

			return Action::Handoff;
		}
		if ($classification->requiresHuman || $classification->recommendedAction === Action::Handoff) {
			$reasons[] = 'model_requires_human';

			return Action::Handoff;
		}

		$action = match ($category) {
			Category::LegitimateCriticism, Category::Praise => Action::Keep,
			Category::Spam, Category::Scam => $this->hide($classification, $settings, $reasons),
			Category::Faq => $this->reply($classification, $settings, $reasons),
			default => Action::Review,
		};
		if ($action === Action::Review && $reasons === []) {
			$reasons[] = 'category_needs_review';
		}

		// The model can only make an external action more cautious.
		if ($action->isExternal() && $classification->recommendedAction !== $action) {
			$reasons[] = 'model_disagrees';
			$action = Action::Review;
		}
		if ($action === Action::Reply && $moderationFlagged) {
			$reasons[] = 'moderation_flagged';
			$action = Action::Review;
		}

		return $action;
	}


	/**
	 * @param list<string> $reasons
	 */
	private function hide(Classification $classification, PolicySettings $settings, array &$reasons): Action
	{
		if (
			$classification->confidence >= $settings->hideMinConfidence
			&& $classification->spamProbability >= $settings->hideMinSpamProbability
		) {
			return Action::Hide;
		}

		$reasons[] = 'below_hide_threshold';

		return Action::Review;
	}


	/**
	 * @param list<string> $reasons
	 */
	private function reply(Classification $classification, PolicySettings $settings, array &$reasons): Action
	{
		if (!in_array($classification->language, $settings->languages, true)) {
			$reasons[] = 'unsupported_language';

			return $settings->otherLanguage;
		}
		if ($classification->confidence < $settings->replyMinConfidence) {
			$reasons[] = 'below_reply_threshold';

			return Action::Review;
		}

		return Action::Reply;
	}


	/**
	 * @param list<string> $reasons
	 */
	private function allowsAutomatic(
		Action $action,
		Classification $classification,
		Policy $policy,
		AutomationContext $context,
		array &$reasons,
	): bool {
		$blocked = [];
		if ($policy->mode !== Mode::Automatic) {
			$blocked[] = 'mode_' . $policy->mode->value;
		}
		if ($context->paused) {
			$blocked[] = 'automation_paused';
		}
		if (!$context->accountActive) {
			$blocked[] = 'account_inactive';
		}
		if ($action === Action::Hide && !$policy->settings->autoHide) {
			$blocked[] = 'auto_hide_disabled';
		}
		if (
			$action === Action::Reply
			&& !in_array($classification->category, $policy->settings->autoReplyCategories, true)
		) {
			$blocked[] = 'auto_reply_not_allowed';
		}

		array_push($reasons, ...$blocked);

		return $blocked === [];
	}
}
