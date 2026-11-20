<?php

declare(strict_types=1);

use App\Knowledge\InvalidKnowledge;
use App\Knowledge\SourceType;
use Tester\Assert;
use Tests\Support\TestDatabase;
use Tests\Support\TestServices;

require __DIR__ . '/../../bootstrap.php';


$database = TestDatabase::create();
$database->query('INSERT INTO operators ?', [
	'email' => 'admin@example.test',
	'name' => 'Admin',
	'role' => 'admin',
	'password_hash' => 'x',
]);
$knowledge = TestServices::knowledge($database);


test('drafts are versioned per key and locale and never reach the AI', function () use ($knowledge): void {
	$v1 = $knowledge->createDraft('pricing_current', 'cs', SourceType::Pricing, 'Ceník', 'Kurz stojí 990 Kč.', null, null, 1);
	$v2 = $knowledge->createDraft('pricing_current', 'cs', SourceType::Pricing, 'Ceník', 'Kurz stojí 1 090 Kč.', null, null, 1);
	$en = $knowledge->createDraft('pricing_current', 'en', SourceType::Pricing, 'Pricing', 'The course costs EUR 40.', null, null, 1);

	Assert::same(1, $knowledge->find($v1)?->version);
	Assert::same(2, $knowledge->find($v2)?->version);
	Assert::same(1, $knowledge->find($en)?->version);
	Assert::same([], $knowledge->current(['pricing_current'], 'cs'));
	Assert::same([], $knowledge->publishedKeys());
});


test('publishing one version archives the previous one', function () use ($knowledge): void {
	[$v2, $v1] = $knowledge->versions('pricing_current', 'cs');
	$knowledge->publish($v1->id, 1);
	Assert::same('Kurz stojí 990 Kč.', $knowledge->current(['pricing_current'], 'cs')[0]->content ?? null);

	$knowledge->publish($v2->id, 1);
	Assert::same(['archived', 'published'], [$knowledge->find($v1->id)?->status, $knowledge->find($v2->id)?->status]);
	Assert::same('Kurz stojí 1 090 Kč.', $knowledge->current(['pricing_current'], 'cs')[0]->content ?? null);
	Assert::same([], $knowledge->current(['pricing_current'], 'en'));
	Assert::same(['pricing_current' => 'Ceník'], $knowledge->publishedKeys());

	$knowledge->unpublish($v2->id, 1);
	Assert::same([], $knowledge->current(['pricing_current'], 'cs'));
});


test('validity limits the current knowledge', function () use ($knowledge): void {
	$past = $knowledge->createDraft('summer_offer', 'cs', SourceType::Other, 'Léto', 'Sleva.', new DateTimeImmutable('2020-06-01'), new DateTimeImmutable('2020-08-31'), 1);
	$future = $knowledge->createDraft('winter_offer', 'cs', SourceType::Other, 'Zima', 'Sleva.', new DateTimeImmutable('+1 year'), null, 1);
	$knowledge->publish($past, 1);
	$knowledge->publish($future, 1);

	Assert::same([], $knowledge->current(['summer_offer', 'winter_offer'], 'cs'));
	Assert::same([], $knowledge->publishedKeys());
});


test('invalid knowledge is rejected', function () use ($knowledge): void {
	Assert::exception(
		fn() => $knowledge->createDraft('Bad Key', 'cs', SourceType::Faq, 'T', 'C', null, null, 1),
		InvalidKnowledge::class,
		'knowledge.error.key',
	);
	Assert::exception(
		fn() => $knowledge->createDraft('faq_x', 'de', SourceType::Faq, 'T', 'C', null, null, 1),
		InvalidKnowledge::class,
		'knowledge.error.locale',
	);
	Assert::exception(
		fn() => $knowledge->createDraft('faq_x', 'cs', SourceType::Faq, 'T', 'C', new DateTimeImmutable('2026-02-01'), new DateTimeImmutable('2026-01-01'), 1),
		InvalidKnowledge::class,
		'knowledge.error.validity',
	);
});
