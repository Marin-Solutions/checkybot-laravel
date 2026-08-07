<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Models\MonitorState;

function apiBuilderOperator(string $project, array $authorizedProjects): GenericUser
{
    return new GenericUser([
        'id' => 'api-builder-operator',
        'current_project_id' => $project,
        'project_ids' => $authorizedProjects,
    ]);
}

function apiBuilderMonitor(string $project, ?string $monitor = null, string $type = 'api'): MonitorState
{
    return MonitorState::query()->create([
        'project_id' => $project,
        'monitor_id' => $monitor ?? (string) Str::uuid(),
        'monitor_type' => $type,
        'state' => 'healthy',
        'severity' => 'warn',
        'observed_at' => now(),
    ]);
}

function apiBuilderPayload(int $version, array $override = []): array
{
    return array_replace([
        'endpoint' => 'https://sample.example.test/v1',
        'method' => 'POST',
        'headers' => [],
        'assertions' => [],
        'configuration_version' => $version,
    ], $override);
}
