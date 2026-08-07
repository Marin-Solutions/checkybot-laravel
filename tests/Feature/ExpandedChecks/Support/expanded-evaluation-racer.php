<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Actions\EvaluateResponseBudget;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Models\ExpandedWebsiteMonitor;

if ($argc !== 6) {
    fwrite(STDERR, "Usage: expanded-evaluation-racer.php <root> <barrier> <ready> <monitor-id> <observed-at>\n");
    exit(64);
}

[, $root, $barrier, $ready, $monitorId, $observedAt] = $argv;
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

$monitor = ExpandedWebsiteMonitor::query()->findOrFail((int) $monitorId);
$app->make(EvaluateResponseBudget::class)->execute($monitor, CarbonImmutable::parse($observedAt)->utc());
exit(0);
