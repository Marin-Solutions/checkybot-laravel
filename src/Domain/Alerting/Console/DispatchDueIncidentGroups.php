<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Console;

use Illuminate\Console\Command;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Jobs\EmitIncidentIntent;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\IncidentGroup;

final class DispatchDueIncidentGroups extends Command
{
    protected $signature = 'checkybot:alerting-groups {--limit=100}';

    protected $description = 'Recover and dispatch due grouped incident collection jobs';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $leaseCutoff = now()->subMinute();
        $groupIds = IncidentGroup::query()
            ->whereNull('closed_at')
            ->whereNull('incident_emitted_at')
            ->where('collection_due_at', '<=', now())
            ->where(static fn ($query) => $query->whereNull('collection_dispatched_at')->orWhere('collection_dispatched_at', '<=', $leaseCutoff))
            ->orderBy('collection_due_at')
            ->limit($limit)
            ->pluck('public_id');
        $dispatched = 0;

        foreach ($groupIds as $groupId) {
            $claimed = IncidentGroup::query()
                ->where('public_id', $groupId)
                ->whereNull('closed_at')
                ->whereNull('incident_emitted_at')
                ->where('collection_due_at', '<=', now())
                ->where(static fn ($query) => $query->whereNull('collection_dispatched_at')->orWhere('collection_dispatched_at', '<=', $leaseCutoff))
                ->update(['collection_dispatched_at' => now(), 'updated_at' => now()]);
            if ($claimed !== 1) {
                continue;
            }

            EmitIncidentIntent::dispatch((string) $groupId);
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} due incident group(s).");

        return self::SUCCESS;
    }
}
