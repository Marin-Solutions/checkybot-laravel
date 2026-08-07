<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Jobs\EvaluateResponseBudgetJob;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Models\ExpandedWebsiteMonitor;

final class EvaluateDueResponseBudgets extends Command
{
    protected $signature = 'checkybot:expanded-evaluate-response-budgets';

    protected $description = 'Queue one-minute p95 response-budget evaluations';

    public function handle(): int
    {
        $observedAt = CarbonImmutable::now('UTC')->startOfMinute()->toRfc3339String();
        $ids = ExpandedWebsiteMonitor::query()->where('check_type', 'response_budget')->where('enabled', true)->pluck('id');
        foreach ($ids as $id) {
            EvaluateResponseBudgetJob::dispatch((int) $id, $observedAt);
        }
        $this->info("Queued {$ids->count()} response budget job(s).");

        return self::SUCCESS;
    }
}
