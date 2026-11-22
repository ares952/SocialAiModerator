<?php

declare(strict_types=1);

namespace App\Monitoring;


/**
 * Buckets of the dashboard charts. Hourly buckets follow UTC hours, daily buckets follow calendar
 * days of the display time zone (a day has 23 or 25 hours when the clocks change). The database
 * works in UTC and groups rows by UTC hour; position() places such an hour into its bucket.
 *
 * With more periods the timeline also covers the equally long periods before the current one,
 * so the dashboard can compare the current numbers with the previous ones.
 */
final readonly class Timeline
{
	/**
	 * @param list<\DateTimeImmutable> $starts local starts of the buckets, oldest first
	 * @param array<string, int> $positions bucket key => position
	 */
	private function __construct(
		public MetricRange $range,
		public array $starts,
		public \DateTimeImmutable $since,
		public \DateTimeImmutable $until,
		private \DateTimeZone $zone,
		private array $positions,
	) {
	}


	public static function create(MetricRange $range, \DateTimeImmutable $now, \DateTimeZone $zone, int $periods = 1): self
	{
		$utc = new \DateTimeZone('UTC');
		$count = $range->buckets() * $periods;
		$starts = [];
		if ($range->hourly()) {
			$hour = $now->setTimezone($utc);
			$last = $hour->setTime((int) $hour->format('G'), 0);
			for ($i = $count - 1; $i >= 0; $i--) {
				$starts[] = $last->modify("-$i hour")->setTimezone($zone);
			}
			$until = $last->modify('+1 hour');
		} else {
			$today = $now->setTimezone($zone)->setTime(0, 0);
			for ($i = $count - 1; $i >= 0; $i--) {
				$starts[] = $today->modify("-$i day");
			}
			$until = $today->modify('+1 day');
		}

		$positions = [];
		foreach ($starts as $position => $start) {
			$positions[self::key($range, $zone, $start)] = $position;
		}

		return new self($range, $starts, $starts[0]->setTimezone($utc), $until->setTimezone($utc), $zone, $positions);
	}


	/**
	 * Only the current period, without the periods before it.
	 */
	public function current(): self
	{
		$starts = array_slice($this->starts, -$this->range->buckets());
		$positions = [];
		foreach ($starts as $position => $start) {
			$positions[self::key($this->range, $this->zone, $start)] = $position;
		}

		return new self($this->range, $starts, $starts[0]->setTimezone(new \DateTimeZone('UTC')), $this->until, $this->zone, $positions);
	}


	/**
	 * Bucket of a UTC hour as the database formats it ('Y-m-d H:00:00'), null outside the timeline.
	 */
	public function position(string $utcHour): ?int
	{
		$time = new \DateTimeImmutable($utcHour, new \DateTimeZone('UTC'));

		return $this->positions[self::key($this->range, $this->zone, $time)] ?? null;
	}


	/**
	 * @return list<int> one zero per bucket
	 */
	public function zeros(): array
	{
		return array_fill(0, count($this->starts), 0);
	}


	/**
	 * @template T
	 * @param list<T> $values one value per bucket
	 * @return list<T> values of the current period
	 */
	public function last(array $values): array
	{
		return array_slice($values, -$this->range->buckets());
	}


	/**
	 * @template T
	 * @param list<T> $values one value per bucket
	 * @return list<T> values of the period just before the current one, empty for a single period
	 */
	public function previous(array $values): array
	{
		$buckets = $this->range->buckets();

		return count($values) < 2 * $buckets ? [] : array_slice($values, -2 * $buckets, $buckets);
	}


	private static function key(MetricRange $range, \DateTimeZone $zone, \DateTimeImmutable $time): string
	{
		return $range->hourly()
			? $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H')
			: $time->setTimezone($zone)->format('Y-m-d');
	}
}
