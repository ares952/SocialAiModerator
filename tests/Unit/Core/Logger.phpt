<?php

declare(strict_types=1);

use App\Core\Correlation;
use App\Core\Logger;
use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Tester\Assert;
use Tracy\ILogger;

require __DIR__ . '/../../bootstrap.php';


$file = TEMP_DIR . '/app.log';
$correlation = new Correlation;
$tracy = new class implements ILogger {
	/** @var list<mixed> */
	public array $logged = [];


	public function log(mixed $value, string $level = self::INFO): void
	{
		$this->logged[] = $value;
	}
};
$logger = new Logger($file, $correlation, $tracy);

/** @return list<array<string, mixed>> */
$lines = static fn(): array => array_map(
	static fn(string $line): array => Json::decode($line, forceArrays: true),
	explode("\n", trim(FileSystem::read($file))),
);


test('every line is a JSON object with time, level, message, correlation and context', function () use ($logger, $correlation, $lines): void {
	$logger->info('Meta webhook received', [
		'events' => 2,
	]);
	$first = $correlation->id();
	$correlation->renew();
	$logger->warning('Job failed', [
		'job_id' => 7,
		'event_id' => 3,
	]);

	[$info, $warning] = $lines();
	Assert::match('%d%-%d%-%d%T%d%:%d%:%d%.%d%Z', $info['time']);
	Assert::same('info', $info['level']);
	Assert::same('Meta webhook received', $info['message']);
	Assert::same($first, $info['correlation_id']);
	Assert::same(2, $info['events']);

	Assert::same('warning', $warning['level']);
	Assert::notSame($first, $warning['correlation_id']);
	Assert::same(7, $warning['job_id']);
});


test('an exception is logged by class and handed over to Tracy', function () use ($logger, $tracy, $lines): void {
	$exception = new RuntimeException('detail');
	$logger->error('Job failed', [], $exception);

	$last = $lines()[2];
	Assert::same('error', $last['level']);
	Assert::same('RuntimeException', $last['exception']);
	Assert::same([$exception], $tracy->logged);
});
