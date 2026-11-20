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


test('homepage defaults to the first language', function () use ($match): void {
	Assert::same('cs', $match('/')['lang'] ?? null);
	Assert::same('en', $match('/en/')['lang'] ?? null);
	Assert::null($match('/de/'));
});


test('unknown path is not routed', function () use ($match): void {
	Assert::null($match('/admin/anything'));
});
