<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();
Tester\Environment::setupFunctions();

// Per-process temp directory, removed when the test finishes (specification §12.10).
define('TEMP_DIR', dirname(__DIR__) . '/temp/tests/' . getmypid());
@mkdir(TEMP_DIR, 0775, true); // @ - may exist from an aborted run
Tester\Helpers::purge(TEMP_DIR);
register_shutdown_function(static function (): void {
	Tester\Helpers::purge(TEMP_DIR);
	rmdir(TEMP_DIR);
});
