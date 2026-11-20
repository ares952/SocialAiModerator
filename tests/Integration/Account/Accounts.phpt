<?php

declare(strict_types=1);

use App\Account\Accounts;
use App\Account\AccountStatus;
use App\Account\InvalidAccount;
use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Meta\Platform;
use Tester\Assert;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';


$database = TestDatabase::create();
$database->query('INSERT INTO operators ?', [
	'email' => 'admin@example.test',
	'name' => 'Admin',
	'role' => 'admin',
	'password_hash' => 'x',
]);
$audit = new AuditLog($database);
$accounts = new Accounts($database, $audit);


test('accounts are stored with the platform prefix the webhook ingest looks up', function () use ($accounts): void {
	$id = $accounts->create(Platform::Facebook, ' 1000000000000001 ', 'Test Page', AccountStatus::Active, 1);
	$account = $accounts->find($id) ?? throw new LogicException;
	Assert::same('fb:1000000000000001', $account->external_id);
	Assert::same('active', $account->status);

	$accounts->create(Platform::Instagram, '1000000000000001', 'Same number on Instagram', AccountStatus::Disabled, 1);
	Assert::same(['Same number on Instagram', 'Test Page'], array_map(fn($row) => $row->name, $accounts->all()));
});


test('invalid or duplicate accounts are rejected', function () use ($accounts): void {
	Assert::exception(
		fn() => $accounts->create(Platform::Facebook, 'fb:123', 'X', AccountStatus::Active, 1),
		InvalidAccount::class,
		'account.error.external-id',
	);
	Assert::exception(
		fn() => $accounts->create(Platform::Facebook, '1000000000000001', 'Twin', AccountStatus::Active, 1),
		InvalidAccount::class,
		'account.error.duplicate',
	);
});


test('status changes are audited and listed in the audit log', function () use ($accounts, $audit): void {
	$id = $accounts->all()[1]->id;
	$accounts->update($id, 'Renamed Page', AccountStatus::Disabled, 1);
	Assert::same('disabled', $accounts->find($id)?->status);

	Assert::same(3, $audit->count(null));
	Assert::same(1, $audit->count(AuditAction::AccountUpdate));
	$entry = $audit->page(AuditAction::AccountUpdate, 10, 0)[0];
	Assert::same('status=active->disabled', $entry->details);
	Assert::same('Admin', $entry->operator_name);
});
