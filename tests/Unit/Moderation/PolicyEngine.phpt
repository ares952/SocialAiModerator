<?php

declare(strict_types=1);

use App\Moderation\Action;
use App\Moderation\AutomationContext;
use App\Moderation\Category;
use App\Moderation\Classification;
use App\Moderation\ContentKind;
use App\Moderation\Mode;
use App\Moderation\Policy;
use App\Moderation\PolicyEngine;
use App\Moderation\PolicySettings;
use App\Moderation\Sentiment;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

// Safety invariants 1, 4, 6, 7 and 12 of AGENTS.md (specification §5.4, §9.1).

$engine = new PolicyEngine;
$open = new AutomationContext(paused: false, accountActive: true);
$automatic = new Policy(7, Mode::Automatic, new PolicySettings(
	autoHide: true,
	autoReplyCategories: [Category::Faq],
));

$classification = static fn(
	Category $category,
	Action $recommended,
	float $confidence = 0.99,
	float $spam = 0.01,
	bool $requiresHuman = false,
	string $language = 'cs',
): Classification => new Classification(
	category: $category,
	language: $language,
	sentiment: Sentiment::Neutral,
	confidence: $confidence,
	spamProbability: $spam,
	recommendedAction: $recommended,
	requiresHuman: $requiresHuman,
	reason: 'test',
	knowledgeRequired: [],
);


test('without a classification the event goes to review (fail closed)', function () use ($engine, $automatic, $open): void {
	$decision = $engine->decide(null, ContentKind::Comment, false, $automatic, $open);
	Assert::same(Action::Review, $decision->action);
	Assert::false($decision->automatic);
	Assert::same(['classification_unavailable'], $decision->reasons);
	Assert::same(7, $decision->policyVersion);
});


test('sensitive categories are always handed off, even when the model recommends reply or hide', function () use ($engine, $automatic, $open, $classification): void {
	foreach ([Category::SupportIssue, Category::Billing, Category::Refund, Category::Health, Category::Legal] as $category) {
		foreach ([Action::Reply, Action::Hide, Action::Keep] as $recommended) {
			$decision = $engine->decide($classification($category, $recommended, spam: 0.99), ContentKind::Comment, false, $automatic, $open);
			Assert::same(Action::Handoff, $decision->action, "$category->value / $recommended->value");
			Assert::false($decision->automatic);
		}
	}
});


test('legitimate criticism is kept even when the model wants to hide it with high spam probability', function () use ($engine, $automatic, $open, $classification): void {
	$decision = $engine->decide(
		$classification(Category::LegitimateCriticism, Action::Hide, confidence: 1.0, spam: 1.0),
		ContentKind::Comment,
		false,
		$automatic,
		$open,
	);
	Assert::same(Action::Keep, $decision->action);
	Assert::false($decision->automatic);
});


test('spam is hidden automatically only above both thresholds and with everything allowed', function () use ($engine, $automatic, $open, $classification): void {
	$decision = $engine->decide($classification(Category::Spam, Action::Hide, 0.97, 0.98), ContentKind::Comment, false, $automatic, $open);
	Assert::same(Action::Hide, $decision->action);
	Assert::true($decision->automatic);
	Assert::same([], $decision->reasons);

	$low = $engine->decide($classification(Category::Spam, Action::Hide, 0.97, 0.90), ContentKind::Comment, false, $automatic, $open);
	Assert::same(Action::Review, $low->action);
	Assert::same(['below_hide_threshold'], $low->reasons);

	$unsure = $engine->decide($classification(Category::Scam, Action::Hide, 0.80, 0.99), ContentKind::Comment, false, $automatic, $open);
	Assert::same(Action::Review, $unsure->action);
});


test('the model can only make a decision more cautious', function () use ($engine, $automatic, $open, $classification): void {
	$keep = $engine->decide($classification(Category::Spam, Action::Keep, 0.99, 0.99), ContentKind::Comment, false, $automatic, $open);
	Assert::same(Action::Review, $keep->action);
	Assert::same(['model_disagrees'], $keep->reasons);

	$handoff = $engine->decide($classification(Category::Faq, Action::Handoff), ContentKind::Comment, false, $automatic, $open);
	Assert::same(Action::Handoff, $handoff->action);

	$human = $engine->decide($classification(Category::Spam, Action::Hide, 0.99, 0.99, requiresHuman: true), ContentKind::Comment, false, $automatic, $open);
	Assert::same(Action::Handoff, $human->action);
});


test('abuse and unknown content go to review, never hidden automatically', function () use ($engine, $automatic, $open, $classification): void {
	foreach ([Category::Abuse, Category::Unknown] as $category) {
		$decision = $engine->decide($classification($category, Action::Hide, 0.99, 0.99), ContentKind::Comment, false, $automatic, $open);
		Assert::same(Action::Review, $decision->action);
		Assert::false($decision->automatic);
	}
});


test('FAQ reply needs the threshold, a supported language and no moderation flag', function () use ($engine, $automatic, $open, $classification): void {
	$reply = $engine->decide($classification(Category::Faq, Action::Reply, 0.95), ContentKind::Comment, false, $automatic, $open);
	Assert::same(Action::Reply, $reply->action);
	Assert::true($reply->automatic);

	Assert::same(Action::Review, $engine->decide($classification(Category::Faq, Action::Reply, 0.85), ContentKind::Comment, false, $automatic, $open)->action);

	$german = $engine->decide($classification(Category::Faq, Action::Reply, language: 'de'), ContentKind::Comment, false, $automatic, $open);
	Assert::same(Action::Handoff, $german->action);
	Assert::same(['unsupported_language'], $german->reasons);

	$flagged = $engine->decide($classification(Category::Faq, Action::Reply), ContentKind::Comment, true, $automatic, $open);
	Assert::same(Action::Review, $flagged->action);
	Assert::same(['moderation_flagged'], $flagged->reasons);
});


test('observe and assisted modes never act automatically', function () use ($engine, $open, $classification): void {
	foreach ([Mode::Observe, Mode::Assisted] as $mode) {
		$policy = new Policy(1, $mode, new PolicySettings(autoHide: true, autoReplyCategories: [Category::Faq]));
		foreach ([
			$classification(Category::Spam, Action::Hide, 0.99, 0.99),
			$classification(Category::Faq, Action::Reply),
		] as $input) {
			$decision = $engine->decide($input, ContentKind::Comment, false, $policy, $open);
			Assert::true($decision->action->isExternal());
			Assert::false($decision->automatic);
			Assert::contains("mode_$mode->value", $decision->reasons);
		}
	}
});


test('panic switch, inactive account and unallowed actions block automatic writes', function () use ($engine, $automatic, $classification): void {
	$spam = $classification(Category::Spam, Action::Hide, 0.99, 0.99);

	$paused = $engine->decide($spam, ContentKind::Comment, false, $automatic, new AutomationContext(paused: true, accountActive: true));
	Assert::same(Action::Hide, $paused->action);
	Assert::false($paused->automatic);
	Assert::same(['automation_paused'], $paused->reasons);

	$inactive = $engine->decide($spam, ContentKind::Comment, false, $automatic, new AutomationContext(paused: false, accountActive: false));
	Assert::false($inactive->automatic);

	$noAutoHide = new Policy(2, Mode::Automatic, new PolicySettings);
	$decision = $engine->decide($spam, ContentKind::Comment, false, $noAutoHide, new AutomationContext(false, true));
	Assert::false($decision->automatic);
	Assert::same(['auto_hide_disabled'], $decision->reasons);
	Assert::same(
		['auto_reply_not_allowed'],
		$engine->decide($classification(Category::Faq, Action::Reply), ContentKind::Comment, false, $noAutoHide, new AutomationContext(false, true))->reasons,
	);
});


test('a private message is never hidden', function () use ($engine, $automatic, $open, $classification): void {
	$decision = $engine->decide($classification(Category::Scam, Action::Hide, 0.99, 0.99), ContentKind::Message, false, $automatic, $open);
	Assert::same(Action::Review, $decision->action);
	Assert::false($decision->automatic);
	Assert::same(['message_not_hideable'], $decision->reasons);
	Assert::same(
		Action::Reply,
		$engine->decide($classification(Category::Faq, Action::Reply), ContentKind::Message, false, $automatic, $open)->action,
	);
});


test('there is no delete action', function (): void {
	Assert::null(Action::tryFrom('delete'));
});
