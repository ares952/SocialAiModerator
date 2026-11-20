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
});


test('settings survive the JSON round trip', function (): void {
	$settings = new PolicySettings(0.97, 0.96, 0.92, true, [Category::Faq], ['cs'], Action::Review);
	Assert::equal($settings, PolicySettings::fromArray($settings->toArray()));
	Assert::equal(new PolicySettings, PolicySettings::fromArray([]));
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
