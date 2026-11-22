<?php

declare(strict_types=1);

use App\Moderation\AbuseRule;
use App\Moderation\AbuseType;
use App\Moderation\Action;
use App\Moderation\Category;
use App\Moderation\InvalidPolicy;
use App\Moderation\PolicySettings;
use App\Moderation\Strictness;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('defaults are the initial proposals of the specification', function (): void {
	$settings = new PolicySettings;
	Assert::same(0.95, $settings->hideMinConfidence);
	Assert::same(0.90, $settings->replyMinConfidence);
	Assert::false($settings->autoHide);
	Assert::same([], $settings->autoReplyCategories);
	Assert::same(Action::Handoff, $settings->otherLanguage);
	Assert::same([], $settings->blockedDomains);
	Assert::same(2, $settings->maxLinks);
	Assert::same(Strictness::Balanced, $settings->strictness);
	Assert::equal(Strictness::Balanced->abuseRules(), $settings->abuseRules);
	Assert::same(Strictness::Balanced->spamHideMin(), $settings->hideMinSpamProbability);
	Assert::null($settings->mixedHideMin);
	Assert::same(1, $settings->maxRepeats);
	Assert::same(24, $settings->repeatWindowHours);
});


test('every preset rules all kinds of abuse and no preset hides a mixed text', function (): void {
	foreach (Strictness::presets() as $strictness) {
		$settings = new PolicySettings(hideMinSpamProbability: $strictness->spamHideMin(), strictness: $strictness);
		foreach (AbuseType::ruled() as $type) {
			Assert::type(AbuseRule::class, $settings->abuseRule($type));
		}
		Assert::null($settings->mixedHideMin);
		Assert::same(Action::Hide, $settings->abuseRule(AbuseType::Threat)->action(0.95), "$strictness->value hides threats");
	}
	Assert::exception(fn() => Strictness::Custom->abuseRules(), LogicException::class);
});


test('settings survive the JSON round trip', function (): void {
	$settings = new PolicySettings(0.97, 0.96, 0.92, true, [Category::Faq], ['cs'], Action::Review, ['spam.example'], 5);
	Assert::equal($settings, PolicySettings::fromArray($settings->toArray()));
	Assert::equal(new PolicySettings, PolicySettings::fromArray([]));

	$off = new PolicySettings(maxLinks: null, maxRepeats: null);
	Assert::null(PolicySettings::fromArray($off->toArray())->maxLinks);
	Assert::null(PolicySettings::fromArray($off->toArray())->maxRepeats);

	$repeats = new PolicySettings(maxRepeats: 3, repeatWindowHours: 72);
	Assert::equal($repeats, PolicySettings::fromArray($repeats->toArray()));
	Assert::equal($repeats, $repeats->withStrictness(Strictness::Balanced, Strictness::Balanced->spamHideMin(), Strictness::Balanced->abuseRules(), null));

	$strict = new PolicySettings(hideMinSpamProbability: 0.90, strictness: Strictness::Strict);
	Assert::equal($strict, PolicySettings::fromArray($strict->toArray()));

	$rules = Strictness::Strict->abuseRules();
	$rules[AbuseType::Insult->value] = new AbuseRule(null, 0.55);
	$custom = new PolicySettings(strictness: Strictness::Custom, abuseRules: $rules, mixedHideMin: 0.97);
	Assert::equal($custom, PolicySettings::fromArray($custom->toArray()));
});


test('stored thresholds win over the preset of the stored level', function (): void {
	$stored = (new PolicySettings)->toArray();
	$stored['abuse_rules']['insult'] = ['hide_min' => 0.99, 'review_min' => 0.75];
	$settings = PolicySettings::fromArray($stored);
	Assert::same(Strictness::Balanced, $settings->strictness);
	Assert::equal(new AbuseRule(0.99, 0.75), $settings->abuseRule(AbuseType::Insult));
});


test('abuse rules are complete and consistent, mixed texts are hidden only by the custom level', function (): void {
	Assert::exception(fn() => new PolicySettings(strictness: Strictness::Custom), InvalidPolicy::class, 'policy.error.abuse-rules');
	Assert::exception(
		fn() => new PolicySettings(abuseRules: [AbuseType::Insult->value => new AbuseRule(0.9, 0.7)]),
		InvalidPolicy::class,
		'policy.error.abuse-rules',
	);
	Assert::exception(fn() => new AbuseRule(0.70, 0.90), InvalidPolicy::class, 'policy.error.review-above-hide');
	Assert::exception(fn() => new AbuseRule(0.40, null), InvalidPolicy::class, 'policy.error.threshold');
	Assert::exception(fn() => new PolicySettings(mixedHideMin: 0.95), InvalidPolicy::class, 'policy.error.mixed-custom');
	Assert::exception(
		fn() => new PolicySettings(strictness: Strictness::Custom, abuseRules: Strictness::Strict->abuseRules(), mixedHideMin: 0.3),
		InvalidPolicy::class,
		'policy.error.threshold',
	);
	Assert::exception(fn() => PolicySettings::fromArray(['strictness' => 'lenient']), InvalidPolicy::class, 'policy.error.strictness');
	Assert::exception(
		fn() => PolicySettings::fromArray(['abuse_rules' => ['insult' => ['hide_min' => '0.9']]]),
		InvalidPolicy::class,
		'policy.error.threshold',
	);
});


test('unsafe settings are rejected', function (): void {
	Assert::exception(fn() => new PolicySettings(hideMinConfidence: 0.3), InvalidPolicy::class, 'policy.error.threshold');
	Assert::exception(fn() => new PolicySettings(replyMinConfidence: 1.5), InvalidPolicy::class, 'policy.error.threshold');
	Assert::exception(fn() => new PolicySettings(otherLanguage: Action::Reply), InvalidPolicy::class);
	Assert::exception(
		fn() => new PolicySettings(autoReplyCategories: [Category::Refund]),
		InvalidPolicy::class,
		'policy.error.handoff-category',
	);
	Assert::exception(
		fn() => PolicySettings::fromArray(['auto_reply_categories' => ['nonsense']]),
		InvalidPolicy::class,
		'policy.error.category',
	);
});


test('blocked domains must be bare lower-case host names', function (): void {
	Assert::same(['spam.example', 'sub.spam.example', 'xn--80ak6aa92e.com', 'příklad.example'], (new PolicySettings(
		blockedDomains: ['spam.example', 'sub.spam.example', 'xn--80ak6aa92e.com', 'příklad.example'],
	))->blockedDomains);
	foreach (['Spam.example', 'https://spam.example', 'spam.example/x', 'localhost', '-spam.example', '*.spam.example', ''] as $domain) {
		Assert::exception(fn() => new PolicySettings(blockedDomains: [$domain]), InvalidPolicy::class, 'policy.error.domain');
	}
});


test('the link limit is between 0 and 20 or off', function (): void {
	Assert::same(0, (new PolicySettings(maxLinks: 0))->maxLinks);
	Assert::same(20, (new PolicySettings(maxLinks: 20))->maxLinks);
	Assert::exception(fn() => new PolicySettings(maxLinks: -1), InvalidPolicy::class, 'policy.error.max-links');
	Assert::exception(fn() => new PolicySettings(maxLinks: 21), InvalidPolicy::class, 'policy.error.max-links');
	Assert::exception(fn() => PolicySettings::fromArray(['max_links' => '3']), InvalidPolicy::class, 'policy.error.max-links');
});


test('the repeat limit is between 0 and 20 or off, the window between 1 and 168 hours', function (): void {
	Assert::same(0, (new PolicySettings(maxRepeats: 0))->maxRepeats);
	Assert::exception(fn() => new PolicySettings(maxRepeats: 21), InvalidPolicy::class, 'policy.error.max-repeats');
	Assert::exception(fn() => PolicySettings::fromArray(['max_repeats' => '2']), InvalidPolicy::class, 'policy.error.max-repeats');
	Assert::same(168, (new PolicySettings(repeatWindowHours: 168))->repeatWindowHours);
	Assert::exception(fn() => new PolicySettings(repeatWindowHours: 0), InvalidPolicy::class, 'policy.error.repeat-window');
	Assert::exception(fn() => new PolicySettings(repeatWindowHours: 169), InvalidPolicy::class, 'policy.error.repeat-window');
	Assert::exception(fn() => PolicySettings::fromArray(['repeat_window_hours' => null]), InvalidPolicy::class, 'policy.error.repeat-window');
});


test('automatic reply limits are part of the settings and validated', function (): void {
	$settings = new PolicySettings(autoRepliesPerConversation: 3, autoRepliesPerDay: 40);
	Assert::same([3, 40], [PolicySettings::fromArray($settings->toArray())->autoRepliesPerConversation, PolicySettings::fromArray($settings->toArray())->autoRepliesPerDay]);
	Assert::same([2, 20], [PolicySettings::fromArray([])->autoRepliesPerConversation, PolicySettings::fromArray([])->autoRepliesPerDay]);
	Assert::exception(fn() => new PolicySettings(autoRepliesPerConversation: 0), App\Moderation\InvalidPolicy::class, 'policy.error.auto-replies-conversation');
	Assert::exception(fn() => new PolicySettings(autoRepliesPerDay: 1001), App\Moderation\InvalidPolicy::class, 'policy.error.auto-replies-day');
	Assert::exception(fn() => PolicySettings::fromArray(['auto_replies_per_day' => null]), App\Moderation\InvalidPolicy::class, 'policy.error.auto-replies-day');
});
