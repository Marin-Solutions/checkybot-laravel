<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Actions\ProcessAcceptedMonitorResult;
use MarinSolutions\CheckybotLaravel\Tests\TestCase;

if ($argc !== 6) {
    fwrite(STDERR, "Usage: result-racer.php <root> <database> <barrier> <ready> <operation-id>\n");
    exit(64);
}

[, $root, $database, $barrier, $ready, $operationId] = $argv;
$root = realpath($root);
$database = realpath($database);
if ($root === false || $database === false || ! str_starts_with($database, $root.DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "Refusing a database outside the workspace.\n");
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
    'driver' => 'sqlite', 'database' => $database, 'prefix' => '',
    'foreign_key_constraints' => true, 'busy_timeout' => 10000,
]);
DB::purge('sqlite');
touch($ready);
$deadline = microtime(true) + 15;
while (! is_file($barrier) && microtime(true) < $deadline) {
    usleep(10_000);
}
if (! is_file($barrier)) {
    fwrite(STDERR, "Barrier timeout.\n");
    exit(66);
}

$app->make(ProcessAcceptedMonitorResult::class)->execute($operationId);
exit(0);
