<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Http\Controllers\WebDashboard;

use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorIdentity;
use MarinSolutions\CheckybotLaravel\Models\MonitorState;

final class ProblemProjection
{
    /** @return array{problems: list<array<string, mixed>>, pagination: array{per_page: int, next_cursor: string|null}} */
    public function forProject(AuthorizedProject $project, DashboardFilters $filters): array
    {
        $query = MonitorState::query()
            ->forProject($project->uuid)
            ->whereIn('state', $filters->states)
            ->when($filters->types !== [], static fn ($query) => $query->whereIn('monitor_type', $filters->types))
            ->when($filters->severities !== [], static fn ($query) => $query->whereIn('severity', $filters->severities))
            ->when($filters->monitorUuids !== [], static fn ($query) => $query->whereIn('monitor_id', $filters->monitorUuids))
            ->orderByDesc('observed_at')
            ->orderByDesc('id');

        $page = $query->cursorPaginate(
            perPage: $filters->perPage,
            columns: ['id', 'project_id', 'monitor_id', 'monitor_type', 'state', 'severity', 'observed_at'],
            cursorName: 'cursor',
            cursor: $filters->cursor,
        );

        $problems = $page->getCollection()->map(static function (MonitorState $state): array {
            $identity = new MonitorIdentity($state->project_id, $state->monitor_id, $state->monitor_type);

            return [
                'identity' => $identity->toArray(),
                'state' => $state->state->value,
                'severity' => $state->severity->value,
                'observed_at' => $state->observed_at->toRfc3339String(),
                'detail_url' => '/checkybot/monitors/'.$state->monitor_type->value.'/'.$state->monitor_id,
            ];
        })->values()->all();

        return [
            'problems' => $problems,
            'pagination' => [
                'per_page' => $filters->perPage,
                'next_cursor' => $page->nextCursor()?->encode(),
            ],
        ];
    }
}
