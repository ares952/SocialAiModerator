<?php

declare(strict_types=1);

use App\Audit\AuditLog;
use App\Knowledge\InvalidKnowledge;
use App\Knowledge\KnowledgeImport;
use Nette\Utils\Json;
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
$knowledge = TestServices::knowledge($database);
$import = new KnowledgeImport($database, $knowledge, new AuditLog($database));


/**
 * @param list<array<string, mixed>> $sources
 */
function knowledgeFile(array $sources): string
{
	return Json::encode([
		'format' => 'social-ai-moderator.knowledge',
		'format_version' => 1,
		'exported_at' => '2026-11-22T08:00:00Z',
		'source' => 'test',
		'sources' => $sources,
	]);
}


$pricing = [
	'key' => 'pricing_current',
	'type' => 'pricing',
	'valid_from' => null,
	'valid_to' => '2027-06-30',
	'locales' => [
		'cs' => [
			'title' => 'Ceník',
			'content' => 'Kurz stojí 990 Kč včetně DPH.',
		],
		'en' => [
			'title' => 'Pricing',
			'content' => 'The course costs CZK 990 incl. VAT.',
		],
	],
];
$contact = [
	'key' => 'contact_support',
	'type' => 'other',
	'valid_from' => null,
	'valid_to' => null,
	'locales' => [
		'cs' => [
			'title' => 'Kontakt',
			'content' => 'Napište na info@example.test.',
		],
	],
];


test('every key and locale becomes a draft, nothing is published', function () use ($import, $knowledge, $database, $pricing, $contact): void {
	Assert::same(
		[
			'created' => 3,
			'skipped' => 0,
		],
		$import->import(knowledgeFile([$pricing, $contact]), 1),
	);

	[$cs] = $knowledge->versions('pricing_current', 'cs');
	Assert::same('draft', $cs->status);
	Assert::same('2027-06-30', $cs->valid_to?->format('Y-m-d'));
	Assert::count(1, $knowledge->versions('pricing_current', 'en'));
	Assert::same([], $knowledge->publishedKeys());
	Assert::same(
		'created 3, skipped 0, source test, exported_at 2026-11-22T08:00:00Z',
		$database->query('SELECT details FROM admin_audit WHERE action = ?', 'knowledge.import')->fetchField(),
	);
});


test('an unchanged file is skipped and a changed text gets a new version', function () use ($import, $knowledge, $pricing, $contact): void {
	Assert::same(
		[
			'created' => 0,
			'skipped' => 3,
		],
		$import->import(knowledgeFile([$pricing, $contact]), 1),
	);

	$pricing['locales']['cs']['content'] = 'Kurz stojí 1 090 Kč včetně DPH.';
	Assert::same(
		[
			'created' => 1,
			'skipped' => 2,
		],
		$import->import(knowledgeFile([$pricing, $contact]), 1),
	);
	Assert::same([2, 1], array_map(fn($row) => $row->version, $knowledge->versions('pricing_current', 'cs')));
});


test('an error anywhere writes nothing and names the field', function () use ($import, $database, $pricing, $contact): void {
	$before = $database->query('SELECT COUNT(*) FROM knowledge_sources')->fetchField();
	$pricing['key'] = 'pricing_next';
	$contact['locales']['de'] = [
		'title' => 'Kontakt',
		'content' => 'Schreiben Sie uns.',
	];

	$e = Assert::exception(
		fn() => $import->import(knowledgeFile([$pricing, $contact]), 1),
		InvalidKnowledge::class,
		'knowledge.error.locale',
	);
	Assert::same('sources[1].locales.de', $e?->field);
	Assert::same($before, $database->query('SELECT COUNT(*) FROM knowledge_sources')->fetchField());
});


test('invalid files are rejected', function () use ($import, $pricing, $contact): void {
	Assert::exception(
		fn() => $import->import('{', 1),
		InvalidKnowledge::class,
		'knowledge.import.error.json',
	);
	Assert::exception(
		fn() => $import->import(Json::encode([
			'format' => 'other',
			'format_version' => 1,
			'sources' => [$pricing],
		]), 1),
		InvalidKnowledge::class,
		'knowledge.import.error.format',
	);

	$e = Assert::exception(
		fn() => $import->import(knowledgeFile([$contact, $contact]), 1),
		InvalidKnowledge::class,
		'knowledge.import.error.duplicate',
	);
	Assert::same('sources[1].key', $e?->field);

	unset($pricing['valid_from']);
	$e = Assert::exception(
		fn() => $import->import(knowledgeFile([$pricing]), 1),
		InvalidKnowledge::class,
		'knowledge.import.error.field',
	);
	Assert::same('sources[0].valid_from', $e?->field);

	$pricing['valid_from'] = '2027-02-30';
	$e = Assert::exception(
		fn() => $import->import(knowledgeFile([$pricing]), 1),
		InvalidKnowledge::class,
		'knowledge.import.error.field',
	);
	Assert::same('sources[0].valid_from', $e?->field);

	$contact['locales']['cs']['title'] = str_repeat('a', 201);
	$e = Assert::exception(
		fn() => $import->import(knowledgeFile([$contact]), 1),
		InvalidKnowledge::class,
		'knowledge.error.title',
	);
	Assert::same('sources[0].locales.cs', $e?->field);
});
