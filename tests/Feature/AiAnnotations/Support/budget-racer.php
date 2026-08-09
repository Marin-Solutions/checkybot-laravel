<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Actions\BudgetLedger;

if ($argc !== 8) {
    fwrite(STDERR, "Usage: budget-racer.php <root> <barrier> <ready> <result> <operation> <project> <at>\n");
    exit(64);
}

[, $root, $barrier, $ready, $resultFile, $operation, $project, $at] = $argv;
require $root.'/vendor/autoload.php';
/** @var Application $app */
$app = require $root.'/scripts/harness/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
touch($ready);
$deadline = microtime(true) + 15;
while (! is_file($barrier) && microtime(true) < $deadline) {
    usleep(20_000);
}
if (! is_file($barrier)) {
    fwrite(STDERR, "Barrier timeout.\n");
    exit(70);
}

try {
    $result = $app->make(BudgetLedger::class)->reserve($operation, $project, CarbonImmutable::parse($at));
    file_put_contents($resultFile, json_encode(['allowed' => $result->allowed, 'acquired' => $result->acquired], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
