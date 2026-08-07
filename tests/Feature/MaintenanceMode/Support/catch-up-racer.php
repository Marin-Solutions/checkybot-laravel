<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use MarinSolutions\CheckybotLaravel\Tests\TestCase;

if (! in_array($argc, [3, 6, 7], true)) {
    fwrite(STDERR, "Usage: catch-up-racer.php <root> <database> [<barrier> <ready> [<clock>] <label>]\n");
    exit(64);
}

[, $root, $database] = $argv;
$root = realpath($root);
$database = realpath($database);
if ($root === false || $database === false || ! str_starts_with($database, $root.DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "Refusing a queue database outside the workspace.\n");
    exit(65);
}
require $root.'/vendor/autoload.php';

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
    'busy_timeout' => 15000,
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

$clock = $argc === 7 ? $argv[5] : (string) getenv('CHECKYBOT_TEST_NOW');
if ($clock !== '') {
    CarbonImmutable::setTestNow(CarbonImmutable::parse($clock));
}

$options = [
    'connection' => 'database',
    '--sleep' => 1,
    '--tries' => 1,
    '--timeout' => 15,
    '--no-interaction' => true,
];

if ($argc >= 6) {
    $label = $argc === 7 ? $argv[6] : $argv[5];
    $options['--queue'] = 'race-'.$label;
    fwrite(STDOUT, '[maintenance-runtime] queue='.$options['--queue'].' pending='.DB::table('jobs')->where('queue', $options['--queue'])->count()."\n");
    $options['--once'] = true;
} else {
    $options['--stop-when-empty'] = true;
}

fwrite(STDOUT, "[maintenance-runtime] php artisan queue:work database\n");
$status = $app->make(Kernel::class)->call('queue:work', $options);
if ($argc >= 6) {
    fwrite(STDOUT, '[maintenance-runtime] remaining='.DB::table('jobs')->where('queue', $options['--queue'])->count()."\n");
}

exit($status);
