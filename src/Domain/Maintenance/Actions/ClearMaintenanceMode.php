<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Maintenance\Actions;

use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Jobs\ProcessMaintenanceCatchUp;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Models\MaintenanceMode;

final class ClearMaintenanceMode
{
    public function execute(MaintenanceMode $mode): void
    {
        if ($mode->cleared_at !== null || $mode->catch_up_claimed_at !== null) {
            return;
        }

        $mode->getConnection()->transaction(function () use ($mode): void {
            $locked = MaintenanceMode::query()->whereKey($mode->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->cleared_at !== null || $locked->catch_up_claimed_at !== null) {
                return;
            }

            $locked->forceFill(['cleared_at' => now(), 'catch_up_queued_at' => now()])->save();
            ProcessMaintenanceCatchUp::dispatch($locked->public_id)->afterCommit();
        }, 5);
    }
}
