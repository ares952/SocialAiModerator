<?php

declare(strict_types=1);

use App\Ai\ClassificationParser;
use App\Ai\InvalidModelOutput;
use App\Ai\ModerationResponse;
use App\Ai\OpenAiFailure;
use App\Ai\Redactor;
use App\Ai\StructuredResponse;
use App\Moderation\AbuseType;
use App\Moderation\Action;
use App\Moderation\Category;
use Nette\Utils\Json;
use Tester\Assert;
use Tests\Fakes\FakeOpenAiClient;

require __DIR__ . '/../../bootstrap.php';


test('personal data is replaced before the text leaves for OpenAI', function (): void {
	Assert::same(
		'Napište mi na [email] nebo volejte [phone], víc na [link: example.com]',
		Redactor::redact('Napište mi na jana.novakova+fy@example.cz nebo volejte +420 777 123 456, víc na https://www.Example.com/promo?x=1'),
	);
	Assert::same('Stojí to 490 Kč na 30 dní?', Redactor::redact('Stojí to 490 Kč na 30 dní?'));
	Assert::same('spam [link: bit.ly]', Redactor::redact('spam www.bit.ly/abc'));
});


test('valid classification is parsed', function (): void {
	$classification = ClassificationParser::parse(FakeOpenAiClient::classification([
		'category' => 'spam',
		'recommended_action' => 'hide',
		'confidence' => 1,
		'abuse_type' => 'insult',
		'abuse_score' => 0.8,
		'knowledge_required' => ['pricing_current', 'invented_key'],
	]), ['pricing_current', 'opening_hours']);
	Assert::same(Category::Spam, $classification->category);
	Assert::same(Action::Hide, $classification->recommendedAction);
	Assert::same(1.0, $classification->confidence);
	Assert::same(AbuseType::Insult, $classification->abuseType);
	Assert::same(0.8, $classification->abuseScore);
	Assert::true($classification->hasSubstance);
	Assert::same(['pricing_current'], $classification->knowledgeRequired, 'keys that were not offered are dropped');
});


test('output outside the contract is rejected', function (): void {
	$invalid = [
		['category' => 'delete_everything'],
		['recommended_action' => 'delete'],
		['confidence' => 1.2],
		['spam_probability' => -0.1],
		['confidence' => '0.9'],
		['language' => 'Czech'],
		['requires_human' => 'no'],
		['abuse_type' => 'hate_speech'],
		['abuse_score' => 1.5],
		['has_substance' => 1],
		['knowledge_required' => ['../etc/passwd']],
		['reason' => str_repeat('x', 501)],
		['extra' => 'field'],
	];
	foreach ($invalid as $override) {
		Assert::exception(
			fn() => ClassificationParser::parse(FakeOpenAiClient::classification($override), []),
			InvalidModelOutput::class,
		);
	}

	$missing = FakeOpenAiClient::classification();
	unset($missing['reason']);
	Assert::exception(fn() => ClassificationParser::parse($missing, []), InvalidModelOutput::class);
});


test('Responses API output is read from the output text', function (): void {
	$response = StructuredResponse::fromApi([
		'status' => 'completed',
		'model' => 'test-model-2026',
		'output' => [
			[
				'type' => 'message',
				'content' => [
					[
						'type' => 'output_text',
						'text' => Json::encode(['category' => 'faq']),
					],
				],
			],
		],
		'usage' => [
			'input_tokens' => 321,
			'output_tokens' => 45,
		],
	]);
	Assert::same(['category' => 'faq'], $response->output);
	Assert::same('test-model-2026', $response->model);
	Assert::same(321, $response->inputTokens);
});


test('refusal, incomplete or broken responses fail permanently', function (): void {
	$cases = [
		['status' => 'incomplete'],
		['status' => 'completed', 'output' => [['content' => [['type' => 'refusal', 'refusal' => 'no']]]]],
		['status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => 'not json']]]]],
		['status' => 'completed', 'output' => []],
	];
	foreach ($cases as $body) {
		$e = Assert::exception(fn() => StructuredResponse::fromApi($body), OpenAiFailure::class);
		Assert::false($e instanceof OpenAiFailure && $e->transient);
	}
});


test('moderation response lists flagged categories', function (): void {
	$moderation = ModerationResponse::fromApi([
		'results' => [
			[
				'flagged' => true,
				'categories' => [
					'harassment' => true,
					'violence' => false,
				],
			],
		],
	]);
	Assert::true($moderation->flagged);
	Assert::same(['harassment'], $moderation->categories);
	Assert::exception(fn() => ModerationResponse::fromApi([]), OpenAiFailure::class);
});
