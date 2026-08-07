<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Jobs\RefreshDomainExpiryJob;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Models\ExpandedWebsiteMonitor;

final class RefreshDueDomains extends Command
{
    protected $signature = 'checkybot:expanded-refresh-domains';

    protected $description = 'Queue the daily authoritative domain-expiry refresh';

    public function handle(): int
    {
        $observedAt = CarbonImmutable::now('UTC')->toRfc3339String();
        $ids = ExpandedWebsiteMonitor::query()->where('check_type', 'domain_expiry')->where('enabled', true)->pluck('id');
        foreach ($ids as $id) {
            RefreshDomainExpiryJob::dispatch((int) $id, $observedAt);
        }
        $this->info("Queued {$ids->count()} domain refresh job(s).");

        return self::SUCCESS;
    }
}
