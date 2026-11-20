<?php

declare(strict_types=1);

use App\Meta\WebhookVerifier;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$verifier = new WebhookVerifier('test-app-secret', 'test-verify-token');
$body = '{"object":"page","entry":[]}';
$signature = 'sha256=' . hash_hmac('sha256', $body, 'test-app-secret');


test('signature over the raw body is accepted', function () use ($verifier, $body, $signature): void {
	Assert::true($verifier->isSigned($body, $signature));
});


test('wrong, missing or foreign signature is rejected', function () use ($verifier, $body, $signature): void {
	Assert::false($verifier->isSigned($body . ' ', $signature));
	Assert::false($verifier->isSigned($body, null));
	Assert::false($verifier->isSigned($body, ''));
	Assert::false($verifier->isSigned($body, substr($signature, 7)));
	Assert::false($verifier->isSigned($body, 'sha256=' . hash_hmac('sha256', $body, 'other-secret')));
	Assert::false($verifier->isSigned($body, 'sha1=' . hash_hmac('sha1', $body, 'test-app-secret')));
});


test('subscription needs mode subscribe and the verify token', function () use ($verifier): void {
	Assert::true($verifier->isSubscription('subscribe', 'test-verify-token'));
	Assert::false($verifier->isSubscription('subscribe', 'wrong'));
	Assert::false($verifier->isSubscription('subscribe', null));
	Assert::false($verifier->isSubscription('unsubscribe', 'test-verify-token'));
	Assert::false($verifier->isSubscription(null, 'test-verify-token'));
});


test('empty secrets never accept a webhook', function (): void {
	Assert::exception(fn() => new WebhookVerifier('', 'token'), LogicException::class);
	Assert::exception(fn() => new WebhookVerifier('secret', ''), LogicException::class);
});
