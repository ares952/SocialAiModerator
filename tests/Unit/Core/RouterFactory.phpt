<?php

declare(strict_types=1);

use App\Core\RouterFactory;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$match = static function (string $path): ?array {
	$request = new Request(new UrlScript('https://moderator.test' . $path, '/'));

	return RouterFactory::createRouter(['cs', 'en'])->match($request);
};


test('health endpoints are routed', function () use ($match): void {
	Assert::same('Health', $match('/health/live')['presenter'] ?? null);
	Assert::same('live', $match('/health/live')['action'] ?? null);
	Assert::same('ready', $match('/health/ready')['action'] ?? null);
});


test('Meta webhook is routed without a language', function () use ($match): void {
	Assert::same('Api:MetaWebhook', $match('/webhooks/meta')['presenter'] ?? null);
	Assert::same('Api:MetaWebhook', $match('/api/meta/webhook')['presenter'] ?? null);
	Assert::null($match('/en/webhooks/meta'));
	Assert::null($match('/en/api/meta/webhook'));
});


test('homepage is the dashboard and defaults to the first language', function () use ($match): void {
	Assert::same('Admin:Dashboard', $match('/')['presenter'] ?? null);
	Assert::same('cs', $match('/')['lang'] ?? null);
	Assert::same('en', $match('/en/')['lang'] ?? null);
	Assert::null($match('/de/'));
});


test('sign-in and administration pages', function () use ($match): void {
	Assert::same('Sign', $match('/sign/in')['presenter'] ?? null);
	Assert::same('en', $match('/en/sign/in')['lang'] ?? null);
	Assert::same('out', $match('/sign/out')['action'] ?? null);
	Assert::null($match('/sign/up'));
	Assert::same('Admin:Events', $match('/admin/events')['presenter'] ?? null);
	$edit = $match('/en/admin/operators/edit/5');
	Assert::same('Admin:Operators', $edit['presenter'] ?? null);
	Assert::same('edit', $edit['action'] ?? null);
	Assert::same('5', $edit['id'] ?? null);
});


test('unknown path is not routed', function () use ($match): void {
	Assert::null($match('/admin'));
	Assert::null($match('/anything'));
});
