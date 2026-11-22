<?php

declare(strict_types=1);

use App\Operator\Authorization;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$acl = Authorization::create();


test('viewer sees the dashboard and events, nothing else', function () use ($acl): void {
	Assert::true($acl->isAllowed('viewer', Authorization::Dashboard, Authorization::View));
	Assert::true($acl->isAllowed('viewer', Authorization::Events, Authorization::View));
	Assert::false($acl->isAllowed('viewer', Authorization::Events, Authorization::Manage));
	Assert::false($acl->isAllowed('viewer', Authorization::Operators, Authorization::View));
	Assert::false($acl->isAllowed('viewer', Authorization::Operators, Authorization::Manage));
});


test('moderator cannot manage operators', function () use ($acl): void {
	Assert::true($acl->isAllowed('moderator', Authorization::Events, Authorization::View));
	Assert::false($acl->isAllowed('moderator', Authorization::Operators, Authorization::Manage));
});


test('admin may do everything', function () use ($acl): void {
	Assert::true($acl->isAllowed('admin', Authorization::Operators, Authorization::Manage));
	Assert::true($acl->isAllowed('admin', Authorization::Events, Authorization::Manage));
});


test('policy, knowledge and evaluation: viewers read, moderators edit, admins manage', function () use ($acl): void {
	foreach ([Authorization::Policy, Authorization::Knowledge, Authorization::Evaluation] as $resource) {
		Assert::true($acl->isAllowed('viewer', $resource, Authorization::View));
		Assert::false($acl->isAllowed('viewer', $resource, Authorization::Edit));
		Assert::false($acl->isAllowed('moderator', $resource, Authorization::Manage));
		Assert::true($acl->isAllowed('admin', $resource, Authorization::Manage));
	}
	Assert::true($acl->isAllowed('moderator', Authorization::Knowledge, Authorization::Edit));
	Assert::true($acl->isAllowed('moderator', Authorization::Evaluation, Authorization::Edit));
	Assert::false($acl->isAllowed('moderator', Authorization::Policy, Authorization::Edit));
});


test('accounts are readable by everyone, audit only by admins', function () use ($acl): void {
	Assert::true($acl->isAllowed('viewer', Authorization::Accounts, Authorization::View));
	Assert::false($acl->isAllowed('moderator', Authorization::Accounts, Authorization::Manage));
	Assert::false($acl->isAllowed('moderator', Authorization::Audit, Authorization::View));
	Assert::true($acl->isAllowed('admin', Authorization::Audit, Authorization::View));
	Assert::false($acl->isAllowed('moderator', Authorization::Alarms, Authorization::Manage));
	Assert::true($acl->isAllowed('admin', Authorization::Alarms, Authorization::Manage));
	Assert::false($acl->isAllowed('moderator', Authorization::Privacy, Authorization::Manage), 'Customer data export and deletion are admin only.');
	Assert::false($acl->isAllowed('viewer', Authorization::Privacy, Authorization::View));
	Assert::true($acl->isAllowed('admin', Authorization::Privacy, Authorization::Manage));
});


test('moderation actions: viewers read, moderators approve and request', function () use ($acl): void {
	Assert::true($acl->isAllowed('viewer', Authorization::Actions, Authorization::View));
	Assert::false($acl->isAllowed('viewer', Authorization::Actions, Authorization::Edit));
	Assert::true($acl->isAllowed('moderator', Authorization::Actions, Authorization::Edit));
	Assert::false($acl->isAllowed('moderator', Authorization::Actions, Authorization::Manage));
});


test('conversations: viewers see metadata, moderators read, take over and release', function () use ($acl): void {
	Assert::true($acl->isAllowed('viewer', Authorization::Conversations, Authorization::View));
	Assert::false($acl->isAllowed('viewer', Authorization::Conversations, Authorization::Edit));
	Assert::true($acl->isAllowed('moderator', Authorization::Conversations, Authorization::Edit));
});
