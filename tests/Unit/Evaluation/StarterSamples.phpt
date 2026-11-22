<?php

declare(strict_types=1);

use App\Evaluation\Sample;
use App\Meta\Platform;
use App\Moderation\Action;
use App\Moderation\Category;
use App\Moderation\ContentKind;
use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

// docs/evaluation/starter-samples.json stays importable and follows the labelling rules of
// docs/evaluation/README.md.

$items = Json::decode(FileSystem::read(__DIR__ . '/../../../docs/evaluation/starter-samples.json'), forceArrays: true);
if (!is_array($items) || !array_is_list($items)) {
	throw new LogicException('The starter samples must be a JSON list.');
}


test('every sample is valid for evaluation:import', function () use ($items): void {
	Assert::true(count($items) >= 60);
	foreach ($items as $index => $item) {
		new Sample(
			platform: Platform::from($item['platform']),
			kind: ContentKind::from($item['kind']),
			language: $item['language'],
			text: $item['text'],
			expectedCategory: Category::from($item['expected_category']),
			expectedAction: Action::from($item['expected_action']),
			note: $item['note'] ?? null,
		);
		Assert::same(
			[],
			array_diff(array_keys($item), [
				'platform',
				'kind',
				'language',
				'text',
				'expected_category',
				'expected_action',
				'note',
			]),
			"#$index",
		);
		Assert::notContains('@', $item['text'], "#$index: no e-mail addresses or handles");
	}
});


test('labels follow the rules of the README', function () use ($items): void {
	foreach ($items as $index => $item) {
		$category = Category::from($item['expected_category']);
		$expected = match (true) {
			$category->requiresHandoff() => 'handoff',
			in_array($category, [Category::Spam, Category::Scam], true) => $item['kind'] === 'message' ? 'review' : 'hide',
			in_array($category, [Category::Abuse, Category::Unknown], true) => 'review',
			$category === Category::Faq => in_array($item['language'], ['cs', 'en', 'es'], true) ? 'reply' : 'handoff',
			default => 'keep',
		};
		Assert::same($expected, $item['expected_action'], "#$index $item[expected_category]");
	}
});


test('all three languages and every category are covered, links are fictitious', function () use ($items): void {
	$languages = array_count_values(array_column($items, 'language'));
	foreach (['cs', 'en', 'es'] as $language) {
		Assert::true(($languages[$language] ?? 0) >= 20, $language);
	}
	Assert::same([], array_values(array_diff(
		array_map(fn(Category $category) => $category->value, Category::cases()),
		array_column($items, 'expected_category'),
	)));
	foreach ($items as $item) {
		preg_match_all('~(?:https?://|www\.)([^\s/]+)~', $item['text'], $matches);
		foreach ($matches[1] as $host) {
			Assert::match('%a%.example.test', $host);
		}
	}
});


test('the strictness samples are importable and labelled for the balanced level', function (): void {
	$items = Json::decode(FileSystem::read(__DIR__ . '/../../../docs/evaluation/strictness-samples.json'), forceArrays: true);
	Assert::true(is_array($items) && array_is_list($items) && count($items) >= 12);
	foreach ($items as $index => $item) {
		$sample = new Sample(
			platform: Platform::from($item['platform']),
			kind: ContentKind::from($item['kind']),
			language: $item['language'],
			text: $item['text'],
			expectedCategory: Category::from($item['expected_category']),
			expectedAction: Action::from($item['expected_action']),
			note: $item['note'] ?? null,
		);
		Assert::notContains('@', $sample->text, "#$index");
		Assert::false(
			$sample->kind === ContentKind::Message && $sample->expectedAction === Action::Hide,
			"#$index: a private message cannot be hidden",
		);
		Assert::false(
			$sample->expectedCategory === Category::LegitimateCriticism && $sample->expectedAction === Action::Hide,
			"#$index: criticism is never hidden by a preset level",
		);
	}
});
