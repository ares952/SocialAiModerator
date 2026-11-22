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
		'/admin/actions' => 'Zásahy u komentářů',
		'/admin/conversations' => 'Zprávy',
		'/admin/accounts' => 'Sociální účty',
		'/admin/accounts/add' => 'Přidat účet',
		'/admin/policy' => 'Pravidla moderace',
		'/admin/knowledge' => 'Znalostní báze',
		'/admin/knowledge/add' => 'Přidat znalost',
		'/admin/knowledge/import' => 'Import ze souboru',
		'/admin/evaluation' => 'Vyhodnocení AI',
		'/admin/evaluation/samples' => '0 vzorků',
		'/admin/evaluation/sample' => 'Přidat vzorek',
		'/admin/operators' => 'Operátoři',
		'/admin/operators/add' => 'Přidat operátora',
		'/admin/audit' => 'Audit',
		'/admin/retention' => 'Retence dat',
		'/admin/alarms' => 'Upozornění',
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
	Assert::same(200, $browser->get('/admin/events?type=comment.created&status=review')->status);
	Assert::same(404, $browser->get('/admin/events?type=nonsense')->status);
	Assert::same(200, $browser->get('/admin/actions?status=pending')->status);
	Assert::same(404, $browser->get('/admin/actions?status=nonsense')->status);
	Assert::same(200, $browser->get('/admin/conversations?filter=takeover')->status);
	Assert::same(404, $browser->get('/admin/conversations?filter=nonsense')->status);
	Assert::same(404, $browser->get('/admin/conversations/detail/999999999')->status);
	Assert::same(404, $browser->get('/admin/events/detail/999999999')->status);
});


test('policy: switches, validation, new version and the panic switch', function () use ($browser): void {
	$page = $browser->get('/admin/policy');
	Assert::same(8, substr_count($page->body, 'role="switch"'));
	Assert::contains('<label class="form-label d-block">', $page->body);

	$fields = [
		'mode' => 'observe',
		'hideMinConfidence' => '0.96',
		'replyMinConfidence' => '0.9',
		'autoReplyCategories' => ['faq'],
		'languages' => 'cs, en, es',
		'otherLanguage' => 'handoff',
		'blockedDomains' => "Spam.example\nwin.example, spam.example",
		'maxLinks' => '3',
		'maxRepeats' => '2',
		'repeatWindowHours' => '48',
		'autoRepliesPerConversation' => '2',
		'autoRepliesPerDay' => '15',
		'send' => '1',
	];
	$invalid = $browser->submit('/admin/policy', 'policyForm', ['hideMinConfidence' => '0.2'] + $fields);
	Assert::same(200, $invalid->status);
	Assert::contains('Prahy musí být mezi 0,5 a 1.', $invalid->messages());

	$domain = $browser->submit('/admin/policy', 'policyForm', ['blockedDomains' => 'https://spam.example/x'] + $fields);
	Assert::same(200, $domain->status);
	Assert::contains('Neplatná doména. Zadejte jen názvy domén (např. spam.example), každou na samostatný řádek.', $domain->messages());

	$window = $browser->submit('/admin/policy', 'policyForm', ['repeatWindowHours' => '200'] + $fields);
	Assert::same(200, $window->status);
	Assert::contains('Okno pro opakování musí být mezi 1 a 168 hodinami.', $window->messages());

	$saved = $browser->submit('/admin/policy', 'policyForm', $fields);
	Assert::same(303, $saved->status);
	$page = $browser->get($saved->location() ?? '');
	Assert::contains('Uložena verze 1 pravidel.', $page->messages());
	Assert::contains("spam.example\nwin.example</textarea>", $page->body);
	Assert::match('%A%name="repeatWindowHours"%A%value="48"%A%', $page->body);
	Assert::match('%A%name="autoRepliesPerDay"%A%value="15"%A%', $page->body);
	$limit = $browser->submit('/admin/policy', 'policyForm', ['autoRepliesPerConversation' => '0'] + $fields);
	Assert::contains('Limit pro konverzaci musí být 1 až 20.', $limit->messages());

	Assert::contains('Vyvážená', $page->body);
	Assert::contains('„Počkej, až tě potkám na ulici.“', $page->body, 'Each level shows its criteria and examples.');

	$strict = $browser->submit('/admin/policy', 'strictnessForm', [
		'strictness' => 'strict',
		'insult_hide' => '0.4',
		'send' => '1',
	]);
	Assert::same(303, $strict->status, 'A preset ignores the custom thresholds.');
	$page = $browser->get($strict->location() ?? '');
	Assert::contains('Uložena verze 2 pravidel.', $page->messages());
	Assert::same(1, preg_match('~<input(?=[^>]*value="strict")(?=[^>]*checked)[^>]*>~', $page->body), 'The strict level is selected.');

	$custom = [
		'strictness' => 'custom',
		'spamHideMin' => '0.95',
		'threat_hide' => '0.8',
		'threat_review' => '0.5',
		'profanity_hide' => '',
		'profanity_review' => '0.7',
		'insult_hide' => '0.7',
		'insult_review' => '0.9',
		'ridicule_hide' => '',
		'ridicule_review' => '',
		'passive_aggression_hide' => '',
		'passive_aggression_review' => '',
		'mixedHideMin' => '0.97',
		'send' => '1',
	];
	$invalid = $browser->submit('/admin/policy', 'strictnessForm', $custom);
	Assert::same(200, $invalid->status);
	Assert::contains('Práh kontroly nesmí být vyšší než práh skrytí.', $invalid->body);

	$saved = $browser->submit('/admin/policy', 'strictnessForm', ['insult_review' => '0.6'] + $custom);
	Assert::same(303, $saved->status);
	$page = $browser->get($saved->location() ?? '');
	Assert::contains('Uložena verze 3 pravidel.', $page->messages());
	Assert::contains('Vlastní (danger zone)', $page->body);

	Assert::same(303, $browser->submit('/admin/policy', 'pauseForm', ['reason' => 'e2e', 'pause' => '1'])->status);
	Assert::contains('automatika vypnuta', $browser->get('/')->body);
	Assert::same(303, $browser->submit('/admin/policy', 'resumeForm', ['send' => '1'])->status);
	Assert::notContains('automatika vypnuta', $browser->get('/')->body);
});


test('accounts: add, reject an invalid ID, pause, edit', function () use ($browser, $data): void {
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
	Assert::contains('Bez tokenu', $list->body);

	$tokenPage = $list->link('~/admin/accounts/token/\d+$~') ?? throw new LogicException('No token link.');
	$token = [
		'accessToken' => 'E2E-SYNTHETIC-TOKEN',
		'type' => 'page',
		'scopes' => 'pages_messaging, pages_read_engagement',
		'neverExpires' => '',
		'expires' => '2099-01-31T12:00',
		'dataAccessExpires' => '',
		'send' => '1',
	];
	Assert::contains('Zadejte oprávnění malými písmeny oddělená čárkou, např. pages_messaging, pages_read_engagement.', $browser->submit($tokenPage, 'tokenForm', ['scopes' => 'Pages Messaging'] + $token)->messages());
	Assert::contains('Vyplňte toto pole.', $browser->submit($tokenPage, 'tokenForm', ['expires' => ''] + $token)->messages());
	Assert::same(303, $browser->submit($tokenPage, 'tokenForm', $token)->status);

	$list = $browser->get('/admin/accounts');
	Assert::contains('Neověřený', $list->body);
	Assert::contains('Platnost do 31. 1. 2099 12:00:00', $list->body);
	Assert::notContains('E2E-SYNTHETIC-TOKEN', $list->body);
	$form = $browser->get($tokenPage);
	Assert::notContains('E2E-SYNTHETIC-TOKEN', $form->body);
	Assert::contains('pages_messaging, pages_read_engagement', $form->body);
	$stored = $data->database->query('SELECT expires_at, encrypted_token FROM account_credentials')->fetch() ?? throw new LogicException;
	Assert::same('2099-01-31 11:00:00', $stored->expires_at->format('Y-m-d H:i:s'));
	Assert::notContains('E2E-SYNTHETIC-TOKEN', $stored->encrypted_token);
	Assert::same(1, substr_count($browser->get('/admin/audit?type=account.token.replace')->body, '<td>Výměna tokenu účtu</td>'));
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

	// The checked draft is published from the overview.
	$draft = preg_match('~name="versions\[\]"[^>]*value="(\d+)"~', $overview, $match) === 1
		? $match[1]
		: throw new LogicException('No draft checkbox.');
	Assert::same(303, $browser->submit('/admin/knowledge', 'publishForm', [
		'versions' => [$draft],
		'publish' => '1',
	])->status);
	$overview = $browser->get('/admin/knowledge')->body;
	Assert::contains('text-bg-success">v2', $overview);
	Assert::notContains('frm-publishForm', $overview);
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


test('the starter evaluation set imports and lists', function () use ($browser): void {
	$root = dirname(__DIR__, 2);
	exec(PHP_BINARY . " $root/bin/console evaluation:import $root/docs/evaluation/starter-samples.json 2>&1", $output, $code);
	Assert::same(0, $code, implode("\n", $output));
	Assert::contains('72 samples imported', implode("\n", $output));

	$samples = $browser->get('/admin/evaluation/samples');
	Assert::same('73 vzorků | Social AI Moderator', $samples->title());
	Assert::contains('Gibt es den Kurs auch auf Deutsch?', $samples->body);
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


test('retention: periods are saved and audited, an invalid one is refused', function () use ($browser, $data): void {
	$response = $browser->submit('/admin/retention', 'retentionForm', [
		'comments' => '180',
		'messages' => '180',
		'other_events' => '',
		'audit' => '0',
	]);
	Assert::same(200, $response->status);
	Assert::contains('Zadejte 1 až 3650 dní, nebo pole nechte prázdné.', $response->body);

	$response = $browser->submit('/admin/retention', 'retentionForm', [
		'comments' => '180',
		'messages' => '180',
		'other_events' => '',
		'audit' => '',
	]);
	Assert::same(303, $response->status);
	Assert::same(['comments' => 180, 'messages' => 180], $data->database->query(
		"SELECT data, days FROM retention_periods WHERE days IS NOT NULL ORDER BY data",
	)->fetchPairs('data', 'days'));
	Assert::same('comments=180, messages=180, other_events=keep, audit=keep', $data->database->query(
		"SELECT details FROM admin_audit WHERE action = 'retention.update' ORDER BY id DESC LIMIT 1",
	)->fetchField());
	$data->database->query('DELETE FROM retention_periods');
});


test('dashboard: alarms and numbers; alarm thresholds are saved and audited', function () use ($browser, $data): void {
	$dashboard = $browser->get('/')->body;
	Assert::contains('Provoz v čase', $dashboard);
	Assert::contains('Spotřeba tokenů', $dashboard);
	Assert::contains('class="sidebar sidebar-dark sidebar-fixed border-end"', $dashboard);
	Assert::contains('/assets/vendor/chartjs-4.6.1/chart.umd.min.js', $dashboard);
	Assert::contains("<canvas data-chart='{\"type\":\"bar\"", $dashboard);
	foreach (['7d', '30d'] as $range) {
		Assert::same(200, $browser->get("/?range=$range")->status, $range);
	}
	Assert::same(404, $browser->get('/?range=nonsense')->status);
	foreach (['/assets/admin/icons.svg', '/assets/admin/charts.js', '/assets/vendor/coreui-5.9.1/js/coreui.bundle.min.js'] as $asset) {
		Assert::same(200, $browser->get($asset)->status, $asset);
	}

	$fields = [
		'token_expiry' => '10',
		'webhook_silence' => '',
		'queue_lag' => '15',
		'failures' => '3',
		'hidden_comments' => '20',
		'stale_approvals' => '24',
		'send' => '1',
	];
	Assert::contains('Zadejte hodnotu v povoleném rozsahu', implode(' ', $browser->submit('/admin/alarms', 'alarmsForm', ['queue_lag' => '0'] + $fields)->messages()));
	Assert::same(303, $browser->submit('/admin/alarms', 'alarmsForm', $fields)->status);
	$saved = $data->database->query('SELECT kind, value FROM alarm_thresholds')->fetchPairs('kind', 'value');
	Assert::same(10, $saved['token_expiry'] ?? null);
	Assert::true(array_key_exists('webhook_silence', $saved) && $saved['webhook_silence'] === null, 'An empty value turns the alarm off.');
	Assert::contains('token_expiry=10, webhook_silence=off', (string) $data->database->query(
		"SELECT details FROM admin_audit WHERE action = 'alarms.update' ORDER BY id DESC LIMIT 1",
	)->fetchField());
});


test('no exception was logged', function (): void {
	Assert::same([], E2eData::exceptions());
});
