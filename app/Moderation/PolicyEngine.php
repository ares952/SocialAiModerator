<?php

declare(strict_types=1);

namespace App\Moderation;

use App\Comment\Visibility;
use App\Outbox\ActionTarget;
use App\Outbox\ActionType;

/**
 * Deterministic decision about a classified comment or message (specification §5.4). The model's
 * output is untrusted advice: it can make a decision more cautious, never less. Rules:
 *
 * - no classification (model error, invalid output) => review (fail closed);
 * - complaints, billing, refunds, health and legal => handoff; the model asking for a human =>
 *   review for abuse, spam, scam and unknown, handoff otherwise;
 * - legitimate criticism and praise are kept, whatever the model recommends, unless a content
 *   rule or an inappropriate tone matched;
 * - spam/scam is hidden only above both hide thresholds, otherwise review; a private message
 *   cannot be hidden, so it goes to review;
 * - inappropriate content (abuse_type) follows the rules of the strictness level: hide, review or
 *   keep; a text with a factual point (criticism, question) is hidden only by the custom level's
 *   mixed threshold, otherwise it goes at most to review (docs/moderation-strictness.md);
 * - a content rule hit (blocked domain, too many links, repeated text) turns keep and reply into
 *   review, so a text that talks the model into "praise" or "faq" still reaches a human; it never
 *   hides on its own;
 * - unknown => review;
 * - FAQ is answered only in a supported language, with published knowledge and above the reply
 *   threshold;
 * - the action runs without a human only in `automatic` mode, without a pause, for an active
 *   account and when the policy explicitly allows that action.
 *
 * Right before an action is sent, blocks() checks the current state again (send time wins over
 * the time of the decision).
 */
final class PolicyEngine
{
	public function decide(
		?Classification $classification,
		CustomerContent $content,
		bool $moderationFlagged,
		Policy $policy,
		AutomationContext $context,
	): Decision {
		$reasons = [];
		$action = $classification === null
			? $this->fail($reasons)
			: $this->action($classification, $moderationFlagged, $policy->settings, $reasons);
		if ($action === Action::Hide && $content->kind === ContentKind::Message) {
			$reasons[] = 'message_not_hideable';
			$action = Action::Review;
		}
		$action = $this->rules($content, $action, $policy->settings, $reasons);

		$automatic = $action->isExternal()
			&& $classification !== null
			&& $this->allowsAutomatic($action, $classification, $policy, $context, $reasons);

		return new Decision($action, $automatic, $reasons, $policy->version);
	}


	/**
	 * Reasons that forbid sending the action now; empty when it may be sent. Nothing is sent in
	 * `observe` mode or during a pause (global or of the account), not even after a human approval;
	 * without a human only `automatic` mode with an allowed automatic hide sends. Unhiding always
	 * needs a human. A comment that is removed or already in the requested state is not touched;
	 * the account's own comment and a comment edited since the action was created are not hidden.
	 *
	 * @return list<string>
	 */
	public function blocks(
		ActionType $type,
		bool $approvedByHuman,
		Policy $policy,
		AutomationContext $context,
		ActionTarget $target,
	): array {
		$blocked = [];
		if ($policy->mode === Mode::Observe) {
			$blocked[] = 'mode_observe';
		} elseif (!$approvedByHuman) {
			if ($policy->mode !== Mode::Automatic) {
				$blocked[] = 'mode_' . $policy->mode->value;
			}
			if ($type === ActionType::Unhide) {
				$blocked[] = 'unhide_needs_human';
			}
			if ($type === ActionType::Hide && !$policy->settings->autoHide) {
				$blocked[] = 'auto_hide_disabled';
			}
		}
		if ($context->paused) {
			$blocked[] = 'automation_paused';
		}
		if (!$context->accountActive) {
			$blocked[] = 'account_inactive';
		}

		$state = match (true) {
			$target->visibility === Visibility::Removed => 'comment_removed',
			$type === ActionType::Hide && $target->visibility === Visibility::Hidden => 'already_hidden',
			$type === ActionType::Unhide && $target->visibility === Visibility::Visible => 'already_visible',
			default => null,
		};
		if ($state !== null) {
			$blocked[] = $state;
		}
		if ($type === ActionType::Hide && $target->own) {
			$blocked[] = 'own_comment';
		}
		if ($type === ActionType::Hide && $target->changed) {
			$blocked[] = 'comment_changed';
		}

		return $blocked;
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

			return in_array($category, [Category::Abuse, Category::Spam, Category::Scam, Category::Unknown], true)
				? Action::Review
				: Action::Handoff;
		}

		$action = match ($category) {
			Category::LegitimateCriticism, Category::Praise, Category::Abuse => Action::Keep,
			Category::Spam, Category::Scam => $this->hide($classification, $settings, $reasons),
			Category::Faq => $this->reply($classification, $settings, $reasons),
			default => Action::Review,
		};
		$action = $this->tone($classification, $action, $settings, $reasons);
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
	 * Applies the strictness rule of the inappropriate content found by the model. Spam, scam and
	 * unknown keep their own handling; legitimate criticism and questions always have a factual
	 * point, so they are never hidden below the custom mixed threshold.
	 *
	 * @param list<string> $reasons
	 */
	private function tone(Classification $classification, Action $action, PolicySettings $settings, array &$reasons): Action
	{
		$category = $classification->category;
		$type = $classification->abuseType;
		if ($type === AbuseType::None) {
			if ($category === Category::Abuse) {
				$reasons[] = 'abuse_type_missing';

				return Action::Review;
			}

			return $action;
		}
		if (!in_array($category, [Category::Abuse, Category::Praise, Category::LegitimateCriticism, Category::Faq], true)) {
			return $action;
		}

		$reasons[] = "abuse_$type->value";
		$score = $classification->abuseScore;
		$tone = $settings->abuseRule($type)->action($score);
		$mixed = $classification->hasSubstance
			|| $category === Category::LegitimateCriticism
			|| $category === Category::Faq;
		if ($tone === Action::Hide && $mixed && ($settings->mixedHideMin === null || $score < $settings->mixedHideMin)) {
			$reasons[] = 'mixed_content';
			$tone = Action::Review;
		}
		if ($tone === Action::Hide && $classification->confidence < $settings->hideMinConfidence) {
			$reasons[] = 'below_hide_threshold';
			$tone = Action::Review;
		}

		return match ($tone) {
			Action::Hide => $this->hidden($type, $reasons),
			Action::Review => $action === Action::Handoff ? $action : $this->reviewed($reasons),
			default => $this->tolerated($category, $action, $reasons),
		};
	}


	/**
	 * @param list<string> $reasons
	 */
	private function hidden(AbuseType $type, array &$reasons): Action
	{
		if ($type === AbuseType::Threat) {
			$reasons[] = 'threat_hidden';
		}

		return Action::Hide;
	}


	/**
	 * @param list<string> $reasons
	 */
	private function reviewed(array &$reasons): Action
	{
		$reasons[] = 'abuse_review';

		return Action::Review;
	}


	/**
	 * @param list<string> $reasons
	 */
	private function tolerated(Category $category, Action $action, array &$reasons): Action
	{
		if ($category === Category::Abuse) {
			$reasons[] = 'abuse_tolerated';
		}

		return $action;
	}


	/**
	 * @param list<string> $reasons
	 */
	private function rules(CustomerContent $content, Action $action, PolicySettings $settings, array &$reasons): Action
	{
		$hits = ContentRules::hits($content, $settings);
		foreach ($hits as $hit) {
			$reasons[] = $hit->value;
		}

		return $hits !== [] && ($action === Action::Keep || $action === Action::Reply)
			? Action::Review
			: $action;
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
		if ($classification->knowledgeRequired === []) {
			$reasons[] = 'knowledge_missing';

			return Action::Handoff;
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
