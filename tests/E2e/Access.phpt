<?php

declare(strict_types=1);

use Tester\Assert;
use Tests\Support\E2eBrowser;
use Tests\Support\E2eData;

require __DIR__ . '/../bootstrap.php';

// Sign-in, roles and CSRF protection through nginx (runs only in bin/e2e).

$anonymous = E2eBrowser::create();
$data = new E2eData;
[$adminEmail, $adminPassword] = $data->operator('admin');
[$viewerEmail, $viewerPassword] = $data->operator('viewer');


test('health and the sign-in page are public, everything else redirects to sign-in', function () use ($anonymous): void {
	Assert::same(200, $anonymous->get('/health/live')->status);
	Assert::same(200, $anonymous->get('/health/ready')->status);
	$signIn = $anonymous->get('/sign/in');
	Assert::same(200, $signIn->status);
	Assert::contains('name="_token_"', $signIn->body);
	Assert::contains('/assets/vendor/bootstrap-5.3.9/css/bootstrap.min.css', $signIn->body);
	Assert::contains('<link rel="icon" href="/favicon.ico" sizes="any">', $signIn->body);
	foreach (['/favicon.ico', '/assets/admin/icon-192.png', '/assets/admin/apple-touch-icon.png'] as $icon) {
		Assert::same(200, $anonymous->get($icon)->status, $icon);
	}

	foreach (['/', '/admin/events', '/admin/policy', '/admin/operators', '/en/admin/audit'] as $path) {
		$response = $anonymous->get($path);
		Assert::same(302, $response->status, $path);
		Assert::match('/%a?%sign/in?backlink=%a%', $response->location() ?? '', $path);
	}
});


test('a wrong password shows a message and signs nobody in', function () use ($adminEmail): void {
	$browser = E2eBrowser::create();
	$response = $browser->signIn($adminEmail, 'wrong-password');
	Assert::same(200, $response->status);
	Assert::contains('Nesprávný e-mail nebo heslo.', $response->messages());
	Assert::same(302, $browser->get('/')->status);
});


test('a cross-site form submission is ignored', function () use ($adminEmail, $adminPassword): void {
	$browser = E2eBrowser::create();
	$page = $browser->get('/sign/in');
	preg_match('~name="_token_" value="([^"]*)"~', $page->body, $match);
	$response = $browser->post('/sign/in', [
		'email' => $adminEmail,
		'password' => $adminPassword,
		'_token_' => html_entity_decode($match[1] ?? ''),
		'_do' => 'signInForm-submit',
		'send' => '1',
	], [
		'Sec-Fetch-Site' => 'cross-site',
	]);
	// Nette answers a cross-origin submission with a redirect back to the form (detectedCsrf);
	// a successful sign-in would redirect to the dashboard.
	Assert::same('/sign/in', $response->location());
	Assert::same(302, $browser->get('/')->status);
});


test('a viewer reads but cannot change anything', function () use ($viewerEmail, $viewerPassword): void {
	$browser = E2eBrowser::create();
	Assert::same(303, $browser->signIn($viewerEmail, $viewerPassword)->status);

	foreach (['/', '/admin/events', '/admin/accounts', '/admin/policy', '/admin/knowledge', '/admin/evaluation'] as $path) {
		Assert::same(200, $browser->get($path)->status, $path);
	}
	foreach (['/admin/operators', '/admin/audit', '/admin/accounts/add', '/admin/accounts/token/1', '/admin/knowledge/add', '/admin/evaluation/samples'] as $path) {
		Assert::same(403, $browser->get($path)->status, $path);
	}

	$policy = $browser->get('/admin/policy');
	Assert::notContains('frm-policyForm', $policy->body);
	Assert::notContains('frm-pauseForm', $policy->body);
	Assert::same(403, $browser->get('/admin/accounts?account=1&do=pause')->status);
});


test('sign-out works only from the same site', function () use ($adminEmail, $adminPassword): void {
	$browser = E2eBrowser::create();
	Assert::same(303, $browser->signIn($adminEmail, $adminPassword)->status);
	$dashboard = $browser->get('/');
	Assert::same(200, $dashboard->status);
	Assert::notNull($dashboard->link('~^/sign/out$~'));

	$browser->get('/sign/out', [
		'Sec-Fetch-Site' => 'cross-site',
	]);
	Assert::same(200, $browser->get('/')->status);

	$browser->get('/sign/out');
	Assert::same(302, $browser->get('/')->status);
});


test('no exception was logged', function (): void {
	Assert::same([], E2eData::exceptions());
});
