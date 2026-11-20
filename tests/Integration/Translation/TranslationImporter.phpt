<?php

declare(strict_types=1);

use App\Translation\InvalidTranslationFile;
use App\Translation\TranslationImporter;
use App\Translation\Translator;
use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Tester\Assert;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';


$database = TestDatabase::create();

/**
 * @param array<string, array<string, string>> $texts key => form => text
 */
$writeFile = static function (string $language, array $texts): void {
	$entries = [];
	foreach ($texts as $key => $forms) {
		$entries[] = [
			'key' => $key,
			'lang' => $language,
			'translations' => $forms,
		];
	}
	FileSystem::write(TEMP_DIR . "/language_$language.json", Json::encode($entries));
};

$writeFile('cs', [
	'item.count' => [
		'1' => '%count% položka',
		'2' => '%count% položky',
		'5' => '%count% položek',
	],
	'item.old' => [
		'1' => 'stará',
		'2' => 'stará',
		'5' => 'stará',
	],
]);
$writeFile('en', [
	'item.count' => [
		'1' => '%count% item',
		'2' => '%count% items',
	],
	'item.old' => [
		'1' => 'old',
		'2' => 'old',
	],
]);

$importer = new TranslationImporter($database, TEMP_DIR);


test('first import inserts all rows', function () use ($importer, $database): void {
	Assert::same(['cs', 'en'], $importer->pending());
	$results = $importer->import();
	Assert::same(6, $results['cs']->changed);
	Assert::same(4, $results['en']->changed);
	Assert::same([], $importer->pending());
	Assert::same(10, $database->table('translations')->count('*'));
});


test('unchanged files are skipped', function () use ($importer): void {
	Assert::true($importer->import()['cs']->skipped);
});


test('translator selects the plural form and replaces placeholders', function () use ($database): void {
	$translator = new Translator($database, ['cs', 'en']);
	Assert::same('1 položka', $translator->translate('item.count', 1));
	Assert::same('3 položky', $translator->translate('item.count', 3));
	Assert::same('7 položek', $translator->translate('item.count', 7));
	Assert::same('missing.key', $translator->translate('missing.key'));

	$translator->setLanguage('en');
	Assert::same('2 items', $translator->translate('item.count', 2));
});


test('manual rows survive, removed keys disappear', function () use ($importer, $database, $writeFile): void {
	$database->query(
		'UPDATE translations t JOIN languages l ON l.id = t.language_id SET t.text = ?, t.is_manual = 1
		WHERE l.code = ? AND t.`key` = ? AND t.plurality = 1',
		'Upraveno ručně',
		'cs',
		'item.count',
	);
	$writeFile('cs', [
		'item.count' => [
			'1' => 'jedna položka',
			'2' => '%count% položky',
			'5' => '%count% položek',
		],
	]);

	$result = $importer->import()['cs'];
	Assert::same(3, $result->removed);
	Assert::same('Upraveno ručně', (new Translator($database, ['cs', 'en']))->translate('item.count', 1));
});


test('file with a missing plural form is rejected', function () use ($importer, $writeFile): void {
	$writeFile('cs', [
		'item.count' => [
			'1' => 'položka',
			'2' => 'položky',
		],
	]);
	Assert::exception(
		fn() => $importer->import(),
		InvalidTranslationFile::class,
		'%a%must define exactly the plural forms 1, 2, 5.',
	);
});
