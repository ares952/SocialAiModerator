<?php

declare(strict_types=1);

use Tester\Assert;
use Tests\Support\E2eBrowser;
use Tests\Support\E2eData;

require __DIR__ . '/../bootstrap.php';

// Every administration page and its main actions as an admin (runs only in bin/e2e).

$browser = E2eBrowser::create();
$data = new E2eData;
[$email, $password] = $data->operator('admin');
Assert::same(303, $browser->signIn($email, $password)->status);


test('every page renders in both languages', function () use ($browser): void {
	$pages = [
		'/' => 'Přehled',
		'/admin/events' => 'Příchozí události',
		'/admin/accounts' => 'Sociální účty',
		'/admin/accounts/add' => 'Přidat účet',
		'/admin/policy' => 'Pravidla moderace',
		'/admin/knowledge' => 'Znalostní báze',
		'/admin/knowledge/add' => 'Přidat znalost',
		'/admin/evaluation' => 'Vyhodnocení AI',
		'/admin/evaluation/samples' => '0 vzorků',
		'/admin/evaluation/sample' => 'Přidat vzorek',
		'/admin/operators' => 'Operátoři',
		'/admin/operators/add' => 'Přidat operátora',
		'/admin/audit' => 'Audit',
		'/admin/password' => 'Změna hesla',
		'/en/' => 'Dashboard',
		'/en/admin/policy' => 'Moderation policy',
		'/en/admin/audit' => 'Audit log',
	];
	foreach ($pages as $path => $title) {
		$response = $browser->get($path);
		Assert::same(200, $response->status, $path);
		Assert::same("$title | Social AI Moderator", $response->title(), $path);
	}
	Assert::same(404, $browser->get('/admin/nonexistent')->status);
	Assert::same(404, $browser->get('/admin/audit?type=nonsense')->status);
});


test('policy: switches, validation, new version and the panic switch', function () use ($browser): void {
	$page = $browser->get('/admin/policy');
	Assert::same(8, substr_count($page->body, 'role="switch"'));
	Assert::contains('<label class="form-label d-block">', $page->body);

	$fields = [
		'mode' => 'observe',
		'hideMinConfidence' => '0.96',
		'hideMinSpamProbability' => '0.95',
		'replyMinConfidence' => '0.9',
		'autoReplyCategories' => ['faq'],
		'languages' => 'cs, en, es',
		'otherLanguage' => 'handoff',
		'send' => '1',
	];
	$invalid = $browser->submit('/admin/policy', 'policyForm', ['hideMinConfidence' => '0.2'] + $fields);
	Assert::same(200, $invalid->status);
	Assert::contains('Prahy musí být mezi 0,5 a 1.', $invalid->messages());

	$saved = $browser->submit('/admin/policy', 'policyForm', $fields);
	Assert::same(303, $saved->status);
	Assert::contains('Uložena verze 1 pravidel.', $browser->get($saved->location() ?? '')->messages());

	Assert::same(303, $browser->submit('/admin/policy', 'pauseForm', ['reason' => 'e2e', 'pause' => '1'])->status);
	Assert::contains('automatika vypnuta', $browser->get('/')->body);
	Assert::same(303, $browser->submit('/admin/policy', 'resumeForm', ['send' => '1'])->status);
	Assert::notContains('automatika vypnuta', $browser->get('/')->body);
});


test('accounts: add, reject an invalid ID, pause, edit', function () use ($browser): void {
	$fields = [
		'platform' => 'facebook',
		'externalId' => '1000000000000099',
		'name' => 'E2E Page',
		'status' => 'active',
		'send' => '1',
	];
	Assert::contains('ID musí být číslo.', $browser->submit('/admin/accounts/add', 'accountForm', ['externalId' => 'abc'] + $fields)->messages());
	Assert::same(303, $browser->submit('/admin/accounts/add', 'accountForm', $fields)->status);

	$list = $browser->get('/admin/accounts');
	Assert::contains('fb:1000000000000099', $list->body);
	$pause = $list->link('~do=pause~') ?? throw new LogicException('No pause link.');
	Assert::same(302, $browser->get($pause)->status);
	$list = $browser->get('/admin/accounts');
	Assert::contains('automatika vypnuta', $list->body);
	Assert::same(302, $browser->get($list->link('~do=resume~') ?? '')->status);

	$edit = $list->link('~/admin/accounts/edit/\d+$~') ?? throw new LogicException('No edit link.');
	Assert::same(303, $browser->submit($edit, 'accountForm', ['name' => 'E2E Page renamed', 'status' => 'disabled', 'send' => '1'])->status);
	Assert::contains('E2E Page renamed', $browser->get('/admin/accounts')->body);

	$audit = $browser->get('/admin/audit?type=account.update');
	Assert::same(1, substr_count($audit->body, '<td>Úprava účtu</td>'));
});


test('knowledge: draft, publish, new version stays a draft', function () use ($browser): void {
	$created = $browser->submit('/admin/knowledge/add', 'sourceForm', [
		'key' => 'pricing_current',
		'locale' => 'cs',
		'type' => 'pricing',
		'title' => 'Ceník',
		'content' => 'Kurz stojí 990 Kč.',
		'validFrom' => '',
		'validTo' => '',
		'send' => '1',
	]);
	Assert::same(303, $created->status);
	$detail = $browser->get($created->location() ?? '');
	Assert::same(302, $browser->get($detail->link('~do=publish~') ?? '')->status);
	Assert::contains('publikováno', $browser->get($created->location() ?? '')->body);

	$edit = $detail->link('~/admin/knowledge/edit/\d+$~') ?? throw new LogicException('No edit link.');
	Assert::same(303, $browser->submit($edit, 'sourceForm', [
		'type' => 'pricing',
		'title' => 'Ceník 2027',
		'content' => 'Kurz stojí 1 090 Kč.',
		'validFrom' => '2027-01-01',
		'validTo' => '',
		'send' => '1',
	])->status);
	$overview = $browser->get('/admin/knowledge')->body;
	Assert::match('%A%<td>v2 <span class="badge text-bg-light border">koncept</span></td>%A%', $overview);
	Assert::contains('text-bg-success">v1', $overview);
});


test('evaluation: a sample is added, a run without a model is refused', function () use ($browser): void {
	Assert::same(303, $browser->submit('/admin/evaluation/sample', 'sampleForm', [
		'platform' => 'facebook',
		'kind' => 'comment',
		'language' => 'cs',
		'text' => 'Smyšlený dotaz: kolik stojí kurz?',
		'expectedCategory' => 'faq',
		'expectedAction' => 'reply',
		'note' => '',
		'send' => '1',
	])->status);
	Assert::same('1 vzorek | Social AI Moderator', $browser->get('/admin/evaluation/samples')->title());

	$start = $browser->get('/admin/evaluation')->link('~do=start~') ?? throw new LogicException('No start link.');
	$response = $browser->get($start);
	Assert::same(302, $response->status);
	Assert::contains('Není nastaven model klasifikátoru (OPENAI_CLASSIFIER_MODEL).', $browser->get($response->location() ?? '')->messages());
});


test('operators: a moderator is added and can sign in', function () use ($browser): void {
	Assert::same(303, $browser->submit('/admin/operators/add', 'addForm', [
		'email' => 'moderator-e2e@example.test',
		'name' => 'Moderator E2E',
		'role' => 'moderator',
		'password' => 'moderator-password-1',
		'passwordCheck' => 'moderator-password-1',
		'send' => '1',
	])->status);

	$moderator = E2eBrowser::create();
	Assert::same(303, $moderator->signIn('moderator-e2e@example.test', 'moderator-password-1')->status);
	Assert::same(200, $moderator->get('/admin/evaluation/samples')->status);
	Assert::same(403, $moderator->get('/admin/operators')->status);
});


test('no exception was logged', function (): void {
	Assert::same([], E2eData::exceptions());
});
