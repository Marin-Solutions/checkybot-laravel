<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use MarinSolutions\CheckybotLaravel\Tests\TestCase;

if ($argc !== 3) {
    fwrite(STDERR, "Usage: queue-worker.php <workspace-root> <sqlite-database>\n");
    exit(64);
}

[$script, $root, $database] = $argv;
$realRoot = realpath($root);
$realDatabase = realpath($database);
if ($realRoot === false || $realDatabase === false || ! str_starts_with($realDatabase, $realRoot.DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "Refusing a queue database outside the workspace.\n");
    exit(65);
}

require $realRoot.'/vendor/autoload.php';

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
    'database' => $realDatabase,
    'prefix' => '',
    'foreign_key_constraints' => true,
    'busy_timeout' => 5000,
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

exit($app->make(Kernel::class)->call('queue:work', [
    'connection' => 'database',
    '--stop-when-empty' => true,
    '--sleep' => 1,
    '--tries' => 1,
    '--timeout' => 15,
    '--no-interaction' => true,
]));
