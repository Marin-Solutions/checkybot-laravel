<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Http\Controllers\WebDashboard;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;
use Throwable;

final class CurrentProjectResolver
{
    public function resolve(Request $request): AuthorizedProject
    {
        $operator = $request->user();
        abort_unless($operator instanceof Authenticatable, 401, 'Unauthenticated.');

        $projectUuid = $this->selectedProjectUuid($operator);
        abort_unless($projectUuid !== null && Uuid::isValid($projectUuid), 403, 'The operator cannot view the selected current project.');
        abort_unless($this->canAccess($operator, $projectUuid), 403, 'The operator cannot view the selected current project.');

        return new AuthorizedProject(strtolower($projectUuid));
    }

    private function selectedProjectUuid(Authenticatable $operator): ?string
    {
        foreach (['currentCheckybotProjectUuid', 'currentProjectUuid', 'getCurrentProjectUuid'] as $method) {
            if (method_exists($operator, $method)) {
                try {
                    $value = $operator->{$method}();
                    if (is_string($value) && $value !== '') {
                        return $value;
                    }
                } catch (Throwable) {
                    return null;
                }
            }
        }

        foreach (['current_project_uuid', 'current_project_id', 'project_id'] as $property) {
            $value = $operator->{$property} ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        foreach (['currentProject', 'current_project'] as $property) {
            $project = $operator->{$property} ?? null;
            if (! is_object($project)) {
                continue;
            }
            foreach (['uuid', 'public_id', 'id'] as $identifier) {
                $value = $project->{$identifier} ?? null;
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        $projectIds = $this->projectIds($operator);

        return count($projectIds) === 1 ? $projectIds[0] : null;
    }

    private function canAccess(Authenticatable $operator, string $projectUuid): bool
    {
        foreach (['canViewCheckybotProject', 'canAccessProject', 'canReadProject'] as $method) {
            if (method_exists($operator, $method)) {
                try {
                    return (bool) $operator->{$method}($projectUuid);
                } catch (Throwable) {
                    return false;
                }
            }
        }

        $projectIds = $this->projectIds($operator);
        if ($projectIds !== []) {
            return in_array(strtolower($projectUuid), $projectIds, true);
        }

        $projectId = $operator->project_id ?? null;
        if (is_string($projectId) && $projectId !== '') {
            return hash_equals(strtolower($projectId), strtolower($projectUuid));
        }

        return (bool) ($operator->is_admin ?? false)
            || in_array(strtolower($projectUuid), array_filter([
                is_string($operator->current_project_uuid ?? null) ? strtolower($operator->current_project_uuid) : null,
                is_string($operator->current_project_id ?? null) ? strtolower($operator->current_project_id) : null,
            ]), true);
    }

    /** @return list<string> */
    private function projectIds(Authenticatable $operator): array
    {
        $values = $operator->project_ids ?? null;
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (mixed $value): string => strtolower((string) $value),
            array_filter($values, static fn (mixed $value): bool => is_string($value) && Uuid::isValid($value)),
        )));
    }
}
