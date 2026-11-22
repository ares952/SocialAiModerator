<?php

declare(strict_types=1);

use App\Monitoring\MetricRange;
use App\Monitoring\Timeline;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

// Buckets of the dashboard charts: UTC hours from the database folded into hours or local days.

$prague = new DateTimeZone('Europe/Prague');


test('24 hours are 24 hourly buckets ending with the current hour', function () use ($prague): void {
	$timeline = Timeline::create(MetricRange::Day, new DateTimeImmutable('2026-11-22 14:35:10', new DateTimeZone('UTC')), $prague);
	Assert::count(24, $timeline->starts);
	Assert::same('2026-11-21 15:00:00', $timeline->since->format('Y-m-d H:i:s'));
	Assert::same('2026-11-22 15:00:00', $timeline->until->format('Y-m-d H:i:s'));
	Assert::same('UTC', $timeline->since->getTimezone()->getName());
	Assert::same('16:00', $timeline->starts[0]->format('H:i'), 'Starts are local times.');
	Assert::same(0, $timeline->position('2026-11-21 15:00:00'));
	Assert::same(23, $timeline->position('2026-11-22 14:00:00'));
	Assert::null($timeline->position('2026-11-21 14:00:00'));
	Assert::null($timeline->position('2026-11-22 15:00:00'));
});


test('days follow the local calendar, also when the clocks go back', function () use ($prague): void {
	// Summer time in Prague ended on 2026-10-25 at 03:00 local time: that day has 25 hours.
	$timeline = Timeline::create(MetricRange::Week, new DateTimeImmutable('2026-10-26 12:00:00', new DateTimeZone('UTC')), $prague);
	Assert::same([
		'2026-10-20',
		'2026-10-21',
		'2026-10-22',
		'2026-10-23',
		'2026-10-24',
		'2026-10-25',
		'2026-10-26',
	], array_map(fn(DateTimeImmutable $start): string => $start->format('Y-m-d'), $timeline->starts));
	Assert::same('2026-10-19 22:00:00', $timeline->since->format('Y-m-d H:i:s'));
	Assert::same('2026-10-26 23:00:00', $timeline->until->format('Y-m-d H:i:s'));
	Assert::same(4, $timeline->position('2026-10-24 21:00:00'), '23:00 local, still Saturday');
	Assert::same(5, $timeline->position('2026-10-24 22:00:00'), 'midnight local (summer time)');
	Assert::same(5, $timeline->position('2026-10-25 22:00:00'), '23:00 local (winter time)');
	Assert::same(6, $timeline->position('2026-10-25 23:00:00'), 'midnight local (winter time)');
	Assert::null($timeline->position('2026-10-19 21:00:00'));
});


test('a timeline of two periods compares the current period with the one before', function () use ($prague): void {
	$timeline = Timeline::create(MetricRange::Week, new DateTimeImmutable('2026-11-22 10:00:00', new DateTimeZone('UTC')), $prague, 2);
	Assert::count(14, $timeline->starts);
	Assert::count(14, $timeline->zeros());
	$values = range(1, 14);
	Assert::same(range(8, 14), $timeline->last($values));
	Assert::same(range(1, 7), $timeline->previous($values));

	$current = $timeline->current();
	Assert::count(7, $current->starts);
	Assert::same('2026-11-15 23:00:00', $current->since->format('Y-m-d H:i:s'));
	Assert::equal($timeline->until, $current->until);
	Assert::same(0, $current->position('2026-11-15 23:00:00'));
	Assert::null($current->position('2026-11-15 22:00:00'));
	Assert::same([], $current->previous(range(1, 7)), 'A single period has nothing to compare with.');
});


test('30 days are 30 daily buckets', function () use ($prague): void {
	$timeline = Timeline::create(MetricRange::Month, new DateTimeImmutable('2026-11-22 23:30:00', new DateTimeZone('UTC')), $prague);
	Assert::count(30, $timeline->starts);
	Assert::same('2026-11-23', $timeline->starts[29]->format('Y-m-d'), '00:30 local is already the next day.');
});
