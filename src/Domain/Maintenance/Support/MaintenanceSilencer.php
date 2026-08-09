<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Maintenance\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Models\MaintenanceMode;

final class MaintenanceSilencer
{
    public function isSilencedNow(string $projectId, ?CarbonImmutable $at = null): bool
    {
        return $this->currentForProject($projectId, $at) !== null;
    }

    public function currentForProject(string $projectId, ?CarbonImmutable $at = null): ?MaintenanceMode
    {
        if (! Schema::hasTable('maintenance_modes')) {
            return null;
        }

        $at ??= CarbonImmutable::instance(now());
        $global = $this->currentGlobal($at);
        if ($global !== null) {
            return $global;
        }

        return MaintenanceMode::query()
            ->where('scope', 'project')
            ->where('project_id', $projectId)
            ->whereNull('cleared_at')
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at)
            ->orderByDesc('starts_at')
            ->first();
    }

    public function currentGlobal(?CarbonImmutable $at = null): ?MaintenanceMode
    {
        if (! Schema::hasTable('maintenance_modes')) {
            return null;
        }

        $at ??= CarbonImmutable::instance(now());

        return MaintenanceMode::query()
            ->where('scope', 'global')
            ->whereNull('cleared_at')
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at)
            ->orderByDesc('starts_at')
            ->first();
    }
}
