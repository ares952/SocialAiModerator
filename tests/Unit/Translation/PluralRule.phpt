<?php

declare(strict_types=1);

use App\Translation\PluralRule;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('czech uses forms 1, 2 (2-4) and 5', function (): void {
	Assert::same(5, PluralRule::form('cs', 0));
	Assert::same(1, PluralRule::form('cs', 1));
	Assert::same(2, PluralRule::form('cs', 2));
	Assert::same(2, PluralRule::form('cs', 4));
	Assert::same(5, PluralRule::form('cs', 5));
	Assert::same(5, PluralRule::form('cs', 22));
});


test('english uses forms 1 and 2', function (): void {
	Assert::same(2, PluralRule::form('en', 0));
	Assert::same(1, PluralRule::form('en', 1));
	Assert::same(2, PluralRule::form('en', 5));
});


test('unsupported language is rejected', function (): void {
	Assert::exception(
		fn() => PluralRule::form('de', 1),
		InvalidArgumentException::class,
	);
});
