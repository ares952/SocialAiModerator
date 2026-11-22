<?php

declare(strict_types=1);

use App\Operator\Authorization;
use App\Operator\Role;
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
	Assert::contains('/assets/vendor/coreui-5.9.1/css/coreui.min.css', $signIn->body);
	$csp = $signIn->headers['content-security-policy'][0] ?? '';
	Assert::match("default-src 'none'; script-src 'self' 'nonce-%S%'; style-src 'self' 'nonce-%S%'; %A%frame-ancestors 'none'; base-uri 'none';", $csp);
	Assert::notContains('unsafe-inline', $csp);
	Assert::same(['DENY'], $signIn->headers['x-frame-options'] ?? null);
	Assert::same(['nosniff'], $signIn->headers['x-content-type-options'] ?? null);
	Assert::same(['same-origin'], $signIn->headers['referrer-policy'] ?? null);
	Assert::false(isset($signIn->headers['x-powered-by']));
	Assert::same(['nosniff'], $anonymous->get('/assets/admin/admin.js')->headers['x-content-type-options'] ?? null);
	Assert::same(0, preg_match('~<script(?![^>]*\ssrc=)[^>]*>|<style|\son[a-z]+=|\sstyle=~i', $signIn->body));
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

	foreach (['/', '/admin/events', '/admin/actions', '/admin/conversations', '/admin/accounts', '/admin/policy', '/admin/knowledge', '/admin/evaluation'] as $path) {
		Assert::same(200, $browser->get($path)->status, $path);
	}
	foreach (['/admin/operators', '/admin/audit', '/admin/retention', '/admin/alarms', '/admin/accounts/add', '/admin/accounts/token/1', '/admin/knowledge/add', '/admin/knowledge/import', '/admin/evaluation/samples'] as $path) {
		Assert::same(403, $browser->get($path)->status, $path);
	}

	$policy = $browser->get('/admin/policy');
	Assert::notContains('frm-policyForm', $policy->body);
	Assert::notContains('frm-pauseForm', $policy->body);
	Assert::same(403, $browser->get('/admin/accounts?account=1&do=pause')->status);
});


test('every role reaches exactly the pages and signals its permissions allow', function () use ($data): void {
	$acl = Authorization::create();
	// path => [resource, privilege] the page or signal requires (App\Operator\Authorization)
	$requirements = [
		'/' => [Authorization::Dashboard, Authorization::View],
		'/admin/events' => [Authorization::Events, Authorization::View],
		'/admin/review' => [Authorization::Events, Authorization::View],
		'/admin/events/detail/999999999?do=resolve' => [Authorization::Events, Authorization::View],
		'/admin/actions' => [Authorization::Actions, Authorization::View],
		'/admin/conversations' => [Authorization::Conversations, Authorization::View],
		'/admin/accounts' => [Authorization::Accounts, Authorization::View],
		'/admin/accounts/add' => [Authorization::Accounts, Authorization::Manage],
		// unknown records: allowed roles get 404 or a message, nothing changes (other tests run in parallel)
		'/admin/accounts?account=999999&do=pause' => [Authorization::Accounts, Authorization::Manage],
		'/admin/policy' => [Authorization::Policy, Authorization::View],
		'/admin/knowledge' => [Authorization::Knowledge, Authorization::View],
		'/admin/knowledge/add' => [Authorization::Knowledge, Authorization::Edit],
		'/admin/knowledge/import' => [Authorization::Knowledge, Authorization::Edit],
		'/admin/knowledge?version=999999&do=publish' => [Authorization::Knowledge, Authorization::Manage],
		'/admin/evaluation' => [Authorization::Evaluation, Authorization::View],
		'/admin/evaluation/samples' => [Authorization::Evaluation, Authorization::Edit],
		'/admin/evaluation/sample' => [Authorization::Evaluation, Authorization::Edit],
		// refused without OPENAI_CLASSIFIER_MODEL, which the e2e stack never sets
		'/admin/evaluation?do=start' => [Authorization::Evaluation, Authorization::Manage],
		'/admin/operators' => [Authorization::Operators, Authorization::Manage],
		'/admin/audit' => [Authorization::Audit, Authorization::View],
		'/admin/retention' => [Authorization::Retention, Authorization::Manage],
		'/admin/alarms' => [Authorization::Alarms, Authorization::Manage],
		'/admin/privacy' => [Authorization::Privacy, Authorization::Manage],
		'/?do=export&chart=traffic' => [Authorization::Dashboard, Authorization::View],
	];
	foreach ([Role::Viewer, Role::Moderator, Role::Admin] as $role) {
		[$email, $password] = $data->operator($role->value);
		$browser = E2eBrowser::create();
		Assert::same(303, $browser->signIn($email, $password)->status, $role->value);
		foreach ($requirements as $path => [$resource, $privilege]) {
			$status = $browser->get($path)->status;
			if ($acl->isAllowed($role->value, $resource, $privilege)) {
				// allowed: the page renders, a signal redirects or reports a missing record
				Assert::contains($status, [200, 302, 303, 404], "$role->value $path");
			} else {
				Assert::same(403, $status, "$role->value $path");
			}
		}
	}
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
