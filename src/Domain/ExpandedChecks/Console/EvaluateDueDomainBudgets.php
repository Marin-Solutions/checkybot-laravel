<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Jobs\EvaluateDomainExpiryJob;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Models\ExpandedWebsiteMonitor;

final class EvaluateDueDomainBudgets extends Command
{
    protected $signature = 'checkybot:expanded-evaluate-domains';

    protected $description = 'Queue one-minute domain-expiry budget evaluations';

    public function handle(): int
    {
        $observedAt = CarbonImmutable::now('UTC')->startOfMinute()->toRfc3339String();
        $ids = ExpandedWebsiteMonitor::query()->where('check_type', 'domain_expiry')->where('enabled', true)->pluck('id');
        foreach ($ids as $id) {
            EvaluateDomainExpiryJob::dispatch((int) $id, $observedAt);
        }
        $this->info("Queued {$ids->count()} domain budget job(s).");

        return self::SUCCESS;
    }
}
