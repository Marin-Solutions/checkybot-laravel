<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use MarinSolutions\CheckybotLaravel\Tests\TestCase;

require $argv[1].'/vendor/autoload.php';

[$script, $root, $database, $barrier, $ready] = $argv;
touch($ready);
$deadline = microtime(true) + 10;
while (! is_file($barrier) && microtime(true) < $deadline) {
    usleep(10_000);
}

if (! is_file($barrier)) {
    fwrite(STDERR, "Barrier timed out.\n");
    exit(2);
}

$testCase = new class('test_placeholder') extends TestCase
{
    public function test_placeholder(): void {}

    public function bootApplication(): Application
    {
        parent::setUp();

        return $this->app;
    }
};
$app = $testCase->bootApplication();
$app['config']->set('database.default', 'sqlite');
$app['config']->set('database.connections.sqlite', [
    'driver' => 'sqlite',
    'database' => $database,
    'prefix' => '',
    'foreign_key_constraints' => true,
    'busy_timeout' => 10000,
]);
$app['config']->set('queue.default', 'database');
$app['config']->set('queue.connections.database', [
    'driver' => 'database',
    'connection' => 'sqlite',
    'table' => 'jobs',
    'queue' => 'default',
    'retry_after' => 90,
    'after_commit' => true,
]);
DB::purge('sqlite');

exit($app->make(Kernel::class)->call('checkybot:foundation-relay', [
    '--limit' => 1,
    '--no-interaction' => true,
]));
