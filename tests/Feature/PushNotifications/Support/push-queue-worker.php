<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use MarinSolutions\CheckybotLaravel\Tests\TestCase;

if ($argc !== 6) {
    fwrite(STDERR, "Usage: push-queue-worker.php <workspace-root> <sqlite-database> <once|empty> <barrier|-> <ready|->\n");
    exit(64);
}

[$script, $root, $database, $mode, $barrier, $ready] = $argv;
$realRoot = realpath($root);
$realDatabase = realpath($database);
if ($realRoot === false || $realDatabase === false || ! str_starts_with($realDatabase, $realRoot.DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "Refusing a queue database outside the workspace.\n");
    exit(65);
}
if (! in_array($mode, ['once', 'empty'], true)) {
    fwrite(STDERR, "Worker mode must be once or empty.\n");
    exit(66);
}
if ($barrier !== '-') {
    touch($ready);
    $deadline = microtime(true) + 30;
    while (! is_file($barrier) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! is_file($barrier)) {
        fwrite(STDERR, "Queue worker barrier timed out.\n");
        exit(67);
    }
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
$app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
$app['config']->set('database.default', 'sqlite');
$app['config']->set('database.connections.sqlite', [
    'driver' => 'sqlite',
    'database' => $realDatabase,
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

$options = [
    'connection' => 'database',
    '--sleep' => 1,
    '--tries' => 4,
    '--timeout' => 15,
    '--no-interaction' => true,
];
$options[$mode === 'once' ? '--once' : '--stop-when-empty'] = true;

exit($app->make(Kernel::class)->call('queue:work', $options));
