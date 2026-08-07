<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Jobs\RequestPullRecheck;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\PullRetryRequest;

final class DispatchDuePullRechecks extends Command
{
    protected $signature = 'checkybot:alerting-retries {--limit=100}';

    protected $description = 'Dispatch due pull-monitor producer rechecks';

    public function handle(): int
    {
        $ids = PullRetryRequest::query()
            ->whereNull('queued_at')
            ->whereNull('canceled_at')
            ->where('due_at', '<=', now())
            ->orderBy('due_at')
            ->limit(max(1, min(1000, (int) $this->option('limit'))))
            ->pluck('public_id');
        $dispatched = 0;

        foreach ($ids as $id) {
            $claimed = DB::transaction(static fn (): bool => PullRetryRequest::query()
                ->where('public_id', $id)
                ->whereNull('queued_at')
                ->whereNull('canceled_at')
                ->where('due_at', '<=', now())
                ->update(['queued_at' => now(), 'updated_at' => now()]) === 1);
            if (! $claimed) {
                continue;
            }
            RequestPullRecheck::dispatch((string) $id);
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} pull recheck(s).");

        return self::SUCCESS;
    }
}
