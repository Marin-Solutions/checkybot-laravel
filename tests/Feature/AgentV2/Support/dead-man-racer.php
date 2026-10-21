<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Symfony\Component\Console\Input\ArgvInput;

if ($argc !== 5) {
    fwrite(STDERR, "Usage: dead-man-racer.php <root> <barrier> <ready> <scan-at>\n");
    exit(64);
}

[, $root, $barrier, $ready, $scanAt] = $argv;
require $root.'/vendor/autoload.php';
CarbonImmutable::setTestNow(CarbonImmutable::parse($scanAt));

/** @var Application $app */
$app = require $root.'/scripts/harness/bootstrap/app.php';
// SQLite has no row locks. Take the write lock when a transaction starts and
// wait for it, so two racers queue up instead of failing with "database is
// locked" when both try to upgrade a read transaction at the same time.
$app->afterBootstrapping(LoadConfiguration::class, static function (Application $app): void {
    $app['config']->set('database.connections.sqlite.busy_timeout', 10000);
    $app['config']->set('database.connections.sqlite.transaction_mode', 'IMMEDIATE');
});
touch($ready);
$deadline = microtime(true) + 15;
while (! is_file($barrier) && microtime(true) < $deadline) {
    usleep(20_000);
}
if (! is_file($barrier)) {
    fwrite(STDERR, "Barrier timeout.\n");
    exit(70);
}

exit($app->handleCommand(new ArgvInput(['artisan', 'checkybot:agent-evaluate-due', '--limit=1', '--no-interaction'])));
