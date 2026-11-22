<?php

declare(strict_types=1);

namespace App\Monitoring;


/**
 * Period of the dashboard charts: the last 24 hours by hour, or the last 7 or 30 local calendar
 * days (today included) by day.
 */
enum MetricRange: string
{
	case Day = '24h';
	case Week = '7d';
	case Month = '30d';


	public function buckets(): int
	{
		return match ($this) {
			self::Day => 24,
			self::Week => 7,
			self::Month => 30,
		};
	}


	public function hourly(): bool
	{
		return $this === self::Day;
	}
}
