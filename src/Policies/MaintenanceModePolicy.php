<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Policies;

use Illuminate\Contracts\Auth\Authenticatable;

final class MaintenanceModePolicy
{
    public function write(Authenticatable $operator, string $scope, ?string $projectId): bool
    {
        if ($scope === 'global') {
            return $this->operatorBoolean($operator, 'canManageGlobalMaintenance', 'maintenance_global');
        }

        return $projectId !== null && $this->operatorProjectBoolean($operator, 'canManageProjectMaintenance', $projectId);
    }

    public function read(Authenticatable $operator, ?string $projectId): bool
    {
        if ($projectId === null) {
            return $this->operatorBoolean($operator, 'canReadGlobalMaintenance', 'maintenance_global');
        }

        return $this->operatorProjectBoolean($operator, 'canReadProjectMaintenance', $projectId)
            || $this->operatorProjectBoolean($operator, 'canManageProjectMaintenance', $projectId);
    }

    private function operatorBoolean(Authenticatable $operator, string $method, string $property): bool
    {
        if (method_exists($operator, $method)) {
            return (bool) $operator->{$method}();
        }

        return (bool) ($operator->{$property} ?? $operator->is_admin ?? false);
    }

    private function operatorProjectBoolean(Authenticatable $operator, string $method, string $projectId): bool
    {
        if (method_exists($operator, $method)) {
            return (bool) $operator->{$method}($projectId);
        }

        $projects = $operator->maintenance_project_ids ?? [];

        return (bool) ($operator->is_admin ?? false)
            || (is_array($projects) && in_array($projectId, $projects, true));
    }
}
