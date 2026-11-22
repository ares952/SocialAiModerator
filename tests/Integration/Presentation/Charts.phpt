<?php

declare(strict_types=1);

use App\Presentation\Admin\Charts;
use App\Translation\Translator;
use Tester\Assert;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

// Chart definitions and their CSV export (no translations imported: labels are the keys).

$translator = new Translator(TestDatabase::create(), ['cs', 'en']);
$charts = new Charts($translator);
$chart = $charts->chart('bar', [
	'1. 11.',
	'2. 11.',
], [
	[
		'label' => '=cmd|calc',
		'color' => 'primary',
		'data' => [
			3,
			null,
		],
	],
	$charts->dataset('chart.count', 'info', [
		1.5,
		-2,
	]),
]);


test('a chart summarises every dataset for screen readers', function () use ($chart): void {
	Assert::same('=cmd|calc: 3, chart.count: -1', $chart['summary'], 'Sums are rounded.');
	Assert::same(['type', 'labels', 'datasets', 'summary'], array_keys($chart));
});


test('Czech CSV uses semicolons, a decimal comma and a BOM, formulas are neutralised', function () use ($charts, $chart, $translator): void {
	$translator->setLanguage('cs');
	Assert::same("\u{FEFF}chart.column.item;'=cmd|calc;chart.count\n\"1. 11.\";3;1,5\n\"2. 11.\";;-2\n", $charts->csv($chart));
});


test('English CSV uses commas and a decimal point', function () use ($charts, $chart, $translator): void {
	$translator->setLanguage('en');
	Assert::same("\u{FEFF}chart.column.item,'=cmd|calc,chart.count\n\"1. 11.\",3,1.5\n\"2. 11.\",,-2\n", $charts->csv($chart));
});


test('a distribution translates its values and colors known ones', function () use ($charts): void {
	$chart = $charts->distribution('doughnut', [
		'facebook' => 5,
		'' => 1,
	], 'platform.', [
		'facebook' => 'facebook',
	]);
	Assert::same(['platform.facebook', 'chart.unclassified'], $chart['labels']);
	Assert::same(['facebook', 'muted'], $chart['datasets'][0]['colors'] ?? null);
	Assert::false($chart['horizontal'] ?? true);
});
