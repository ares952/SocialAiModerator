<?php

declare(strict_types=1);

use App\Audit\AuditLog;
use App\Moderation\AutomationSwitch;
use App\Moderation\Category;
use App\Moderation\Mode;
use App\Moderation\PolicySettings;
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
$database->query('INSERT INTO social_accounts ?', [
	'platform' => 'facebook',
	'external_id' => 'fb:1',
	'name' => 'Test Page',
	'status' => 'active',
]);
$policies = TestServices::policies($database);
$switch = new AutomationSwitch($database, new AuditLog($database));


test('before the first save the default observe policy applies', function () use ($policies): void {
	$policy = $policies->active();
	Assert::same(0, $policy->version);
	Assert::same(Mode::Observe, $policy->mode);
	Assert::equal($policy, $policies->version(0));
	Assert::null($policies->version(5));
});


test('every save is a new active version, older versions stay readable', function () use ($policies, $database): void {
	$first = $policies->save(Mode::Assisted, new PolicySettings(hideMinConfidence: 0.97), 1);
	$second = $policies->save(Mode::Automatic, new PolicySettings(autoReplyCategories: [Category::Faq]), 1);

	Assert::same(1, $first->version);
	Assert::same(2, $second->version);
	Assert::same(2, $policies->active()->version);
	Assert::same(0.97, $policies->version(1)?->settings->hideMinConfidence);
	Assert::same([Category::Faq], $policies->active()->settings->autoReplyCategories);
	Assert::same([2, 1], array_map(fn($row) => $row->version, $policies->history()));
	Assert::same(1, $database->query('SELECT COUNT(*) FROM moderation_policies WHERE active = 1')->fetchField());
	Assert::same(2, $database->query("SELECT COUNT(*) FROM admin_audit WHERE action = 'policy.update'")->fetchField());
});


test('global pause blocks every account, an account pause only that account', function () use ($switch, $database): void {
	Assert::false($switch->isPaused(null));
	Assert::false($switch->isPaused(1));

	$switch->pause(1, 'test', 1);
	Assert::true($switch->isPaused(1));
	Assert::false($switch->isPaused(null));
	Assert::false($switch->isPaused(2));

	$switch->pause(null, 'incident', 1);
	$switch->pause(null, 'again', 1);
	Assert::true($switch->isPaused(2));
	Assert::same('incident', $switch->globalPause()?->reason);

	$switch->resume(null, 1);
	Assert::false($switch->isPaused(2));
	Assert::true($switch->isPaused(1));
	$switch->resume(1, 1);
	Assert::false($switch->isPaused(1));

	Assert::same(
		['automation.pause', 'automation.pause', 'automation.resume', 'automation.resume'],
		$database->query("SELECT action FROM admin_audit WHERE target_type = 'automation' ORDER BY id")->fetchPairs(null, 'action'),
	);
});
