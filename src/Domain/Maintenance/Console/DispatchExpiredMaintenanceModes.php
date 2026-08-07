<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Maintenance\Console;

use Illuminate\Console\Command;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Jobs\ProcessMaintenanceCatchUp;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Models\MaintenanceMode;

final class DispatchExpiredMaintenanceModes extends Command
{
    protected $signature = 'checkybot:maintenance-expire {--limit=100}';

    protected $description = 'Queue catch-up evaluation for expired maintenance modes';

    public function handle(): int
    {
        $due = MaintenanceMode::query()
            ->whereNull('cleared_at')
            ->whereNull('catch_up_queued_at')
            ->whereNull('catch_up_claimed_at')
            ->where('ends_at', '<=', now())
            ->orderBy('ends_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        foreach ($due as $mode) {
            $mode->getConnection()->transaction(function () use ($mode): void {
                $locked = MaintenanceMode::query()->whereKey($mode->getKey())->lockForUpdate()->first();
                if ($locked === null
                    || $locked->catch_up_queued_at !== null
                    || $locked->catch_up_claimed_at !== null
                    || $locked->ends_at->isFuture()) {
                    return;
                }

                $locked->forceFill(['catch_up_queued_at' => now()])->save();
                ProcessMaintenanceCatchUp::dispatch($locked->public_id)->afterCommit();
            }, 5);
        }

        $this->info("Queued {$due->count()} maintenance catch-up evaluation(s).");

        return self::SUCCESS;
    }
}
