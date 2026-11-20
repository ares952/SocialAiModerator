<?php

declare(strict_types=1);

use App\Moderation\ContentRules;
use App\Moderation\PolicySettings;
use App\Moderation\RuleHit;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

// Specification §5.1, step 2. Synthetic texts and *.example domains only.

$blocked = new PolicySettings(blockedDomains: ['spam.example', 'xn--80ak6aa92e.com'], maxLinks: null);
$hits = static fn(string $text, PolicySettings $settings): array => ContentRules::hits($text, $settings);


test('a blocked domain matches in every link form and on subdomains', function () use ($blocked, $hits): void {
	foreach ([
		'Visit https://spam.example/prize now',
		'Visit http://WIN.Spam.Example:8080/x',
		'Visit www.spam.example',
		'Visit spam.example.',
		'(spam.example/abc)',
		'Mehr auf https://xn--80ak6aa92e.com/',
	] as $text) {
		Assert::same([RuleHit::BlockedDomain], $hits($text, $blocked), $text);
	}
});


test('similar domains, e-mail addresses and plain text do not match a blocked domain', function () use ($blocked, $hits): void {
	foreach ([
		'Visit https://notspam.example/',
		'Visit https://spam.example.org/',
		'Napište na info@spam.example',
		'Spam example, nothing else',
		'',
	] as $text) {
		Assert::same([], $hits($text, $blocked), $text);
	}
});


test('too many links are counted across all link forms', function () use ($hits): void {
	$two = new PolicySettings(maxLinks: 2);
	Assert::same([], $hits('See https://a.example and www.b.example', $two));
	Assert::same([RuleHit::TooManyLinks], $hits('See https://a.example, www.b.example and c.example/x', $two));
	Assert::same([RuleHit::TooManyLinks], $hits('https://a.example https://a.example https://a.example', $two), 'repeated links count');

	Assert::same([RuleHit::TooManyLinks], $hits('One link: a.example', new PolicySettings(maxLinks: 0)));
	Assert::same([], $hits('https://a.example https://b.example https://c.example', new PolicySettings(maxLinks: null)));
});


test('Czech text without spaces after a full stop, numbers and e-mails are not links', function () use ($hits): void {
	$none = new PolicySettings(maxLinks: 0);
	Assert::same([], $hits('Dobrý den.Chtěla bych se zeptat na cenu.', $none));
	Assert::same([], $hits('Cena je 1.5 tisíce, kontakt jana@firma.example', $none));
});


test('both rules can match at once', function (): void {
	$settings = new PolicySettings(blockedDomains: ['spam.example'], maxLinks: 1);
	Assert::same(
		[RuleHit::BlockedDomain, RuleHit::TooManyLinks],
		ContentRules::hits('https://spam.example https://other.example', $settings),
	);
});
