<?php

declare(strict_types=1);

use App\Moderation\Action;
use App\Moderation\Category;
use App\Moderation\InvalidPolicy;
use App\Moderation\PolicySettings;
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
});


test('settings survive the JSON round trip', function (): void {
	$settings = new PolicySettings(0.97, 0.96, 0.92, true, [Category::Faq], ['cs'], Action::Review, ['spam.example'], 5);
	Assert::equal($settings, PolicySettings::fromArray($settings->toArray()));
	Assert::equal(new PolicySettings, PolicySettings::fromArray([]));

	$off = new PolicySettings(maxLinks: null);
	Assert::null(PolicySettings::fromArray($off->toArray())->maxLinks);
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
