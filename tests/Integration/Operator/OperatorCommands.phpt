<?php

declare(strict_types=1);

use App\Audit\AuditLog;
use App\Console\OperatorCreateCommand;
use App\Console\OperatorPasswordCommand;
use App\Console\PasswordPrompt;
use App\Operator\Operators;
use Nette\Security\Passwords;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tester\Assert;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';


$database = TestDatabase::create();
$passwords = new Passwords(PASSWORD_BCRYPT, ['cost' => 4]);
$operators = new Operators($database, $passwords, new AuditLog($database));

$tester = static function (string $command) use ($operators): CommandTester {
	$application = new Application;
	$application->addCommands([
		new OperatorCreateCommand($operators, new PasswordPrompt),
		new OperatorPasswordCommand($operators, new PasswordPrompt),
	]);

	return new CommandTester($application->find($command));
};


test('first admin is created from the console with a prompted password', function () use ($tester, $database, $passwords): void {
	$command = $tester('operator:create');
	$command->setInputs([
		'first-admin-password',
		'first-admin-password',
	]);
	Assert::same(0, $command->execute([
		'email' => 'owner@example.test',
		'name' => 'Owner',
	]));
	Assert::contains('created (admin)', $command->getDisplay());
	Assert::notContains('first-admin-password', $command->getDisplay());

	$row = $database->query('SELECT role, password_hash FROM operators')->fetch() ?? throw new LogicException;
	Assert::same('admin', $row->role);
	Assert::true($passwords->verify('first-admin-password', $row->password_hash));
	Assert::null($database->query('SELECT operator_id FROM admin_audit')->fetchField());
});


test('mismatched passwords create nothing', function () use ($tester, $database): void {
	$command = $tester('operator:create');
	$command->setInputs([
		'second-password-1',
		'second-password-2',
	]);
	Assert::same(1, $command->execute([
		'email' => 'second@example.test',
		'name' => 'Second',
		'--role' => 'viewer',
	]));
	Assert::same(1, $database->table('operators')->count('*'));
});


test('password reset from the console', function () use ($tester, $database, $passwords): void {
	$command = $tester('operator:password');
	$command->setInputs([
		'reset-password-123',
		'reset-password-123',
	]);
	Assert::same(0, $command->execute([
		'email' => 'OWNER@example.test',
	]));
	$hash = $database->query('SELECT password_hash FROM operators')->fetchField();
	Assert::true(is_string($hash) && $passwords->verify('reset-password-123', $hash));
});
