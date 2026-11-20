<?php

declare(strict_types=1);

use App\Audit\AuditLog;
use App\Operator\InvalidOperator;
use App\Operator\LoginThrottle;
use App\Operator\OperatorAuthenticator;
use App\Operator\Operators;
use App\Operator\Role;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Nette\Security\AuthenticationException;
use Nette\Security\Passwords;
use Nette\Security\SimpleIdentity;
use Tester\Assert;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';


$database = TestDatabase::create();
// Low cost keeps the test fast; production uses the default.
$passwords = new Passwords(PASSWORD_BCRYPT, ['cost' => 4]);
$operators = new Operators($database, $passwords, new AuditLog($database));
$throttle = new LoginThrottle($database, maxPerEmail: 3, maxPerAddress: 5, windowMinutes: 15);
$authenticator = static fn(string $address = '203.0.113.10'): OperatorAuthenticator => new OperatorAuthenticator(
	$database,
	$passwords,
	$throttle,
	new AuditLog($database),
	new Request(new UrlScript('https://moderator.test/sign/in'), remoteAddress: $address),
);

$adminId = $operators->create(' Admin@Example.TEST ', 'Admin', Role::Admin, 'admin-password-1', actorId: null);
$viewerId = $operators->create('viewer@example.test', 'Viewer', Role::Viewer, 'viewer-password-1', actorId: $adminId);


test('operators are created with normalized e-mail and audited', function () use ($database, $operators, $adminId): void {
	Assert::same('admin@example.test', $operators->find($adminId)?->email);
	Assert::same(
		[
			'operator.create|' . $adminId . '|',
			'operator.create|' . ($adminId + 1) . '|' . $adminId,
		],
		array_map(
			fn($row) => "$row->action|$row->target_id|$row->operator_id",
			$database->query('SELECT action, target_id, operator_id FROM admin_audit ORDER BY id')->fetchAll(),
		),
	);
});


test('invalid operators are rejected', function () use ($operators): void {
	Assert::exception(
		fn() => $operators->create('admin@example.test', 'Twin', Role::Viewer, 'long-enough-password', null),
		InvalidOperator::class,
		'operator.error.email-taken',
	);
	Assert::exception(
		fn() => $operators->create('not-an-email', 'X', Role::Viewer, 'long-enough-password', null),
		InvalidOperator::class,
		'operator.error.email',
	);
	Assert::exception(
		fn() => $operators->create('short@example.test', 'X', Role::Viewer, 'short', null),
		InvalidOperator::class,
		'operator.error.password-length',
	);
});


test('the last active admin cannot be demoted or deactivated', function () use ($operators, $adminId, $viewerId): void {
	Assert::exception(
		fn() => $operators->update($adminId, 'Admin', Role::Moderator, true, $adminId),
		InvalidOperator::class,
		'operator.error.last-admin',
	);
	Assert::exception(
		fn() => $operators->update($adminId, 'Admin', Role::Admin, false, $adminId),
		InvalidOperator::class,
		'operator.error.last-admin',
	);

	$operators->update($viewerId, 'Second Admin', Role::Admin, true, $adminId);
	$operators->update($adminId, 'Admin', Role::Moderator, true, $adminId);
	Assert::same('moderator', $operators->find($adminId)?->role);

	$operators->update($adminId, 'Admin', Role::Admin, true, $viewerId);
	$operators->update($viewerId, 'Viewer', Role::Viewer, true, $adminId);
});


test('sign-in with the right password returns the identity with role and version', function () use ($authenticator, $adminId): void {
	$identity = $authenticator()->authenticate('ADMIN@example.test', 'admin-password-1');
	Assert::type(SimpleIdentity::class, $identity);
	Assert::same($adminId, $identity->getId());
	Assert::same(['admin'], $identity->getRoles());
	Assert::same('Admin', $identity->getData()['name']);
});


test('wrong password, unknown e-mail and inactive operator are the same failure', function () use ($authenticator, $operators, $viewerId, $adminId, $database): void {
	$database->query('DELETE FROM login_failures');
	foreach ([['admin@example.test', 'wrong-password'], ['nobody@example.test', 'admin-password-1']] as [$email, $password]) {
		Assert::exception(
			fn() => $authenticator('198.51.100.1')->authenticate($email, $password),
			AuthenticationException::class,
			'sign.in.failed',
		);
	}

	$operators->update($viewerId, 'Viewer', Role::Viewer, false, $adminId);
	Assert::exception(
		fn() => $authenticator('198.51.100.2')->authenticate('viewer@example.test', 'viewer-password-1'),
		AuthenticationException::class,
		'sign.in.failed',
	);
	$operators->update($viewerId, 'Viewer', Role::Viewer, true, $adminId);
});


test('repeated failures block the e-mail, also with the right password', function () use ($authenticator, $database): void {
	$database->query('DELETE FROM login_failures');
	for ($i = 0; $i < 3; $i++) {
		Assert::exception(
			fn() => $authenticator('198.51.100.' . $i)->authenticate('admin@example.test', 'wrong'),
			AuthenticationException::class,
			'sign.in.failed',
		);
	}

	Assert::exception(
		fn() => $authenticator('198.51.100.9')->authenticate('admin@example.test', 'admin-password-1'),
		AuthenticationException::class,
		'sign.in.throttled',
	);
	$database->query('DELETE FROM login_failures');
});


test('session is valid until the password changes or the operator is deactivated', function () use ($authenticator, $operators, $adminId, $viewerId): void {
	$auth = $authenticator();
	$stored = $auth->sleepIdentity($auth->authenticate('viewer@example.test', 'viewer-password-1'));
	Assert::same([], $stored->getRoles());
	Assert::same($viewerId, $auth->wakeupIdentity($stored)?->getId());

	$operators->update($viewerId, 'Viewer', Role::Moderator, true, $adminId);
	Assert::same(['moderator'], $auth->wakeupIdentity($stored)?->getRoles());

	$operators->changePassword($viewerId, 'viewer-password-2', $viewerId);
	Assert::null($auth->wakeupIdentity($stored));

	$stored = $auth->sleepIdentity($auth->authenticate('viewer@example.test', 'viewer-password-2'));
	$operators->update($viewerId, 'Viewer', Role::Viewer, false, $adminId);
	Assert::null($auth->wakeupIdentity($stored));
});
