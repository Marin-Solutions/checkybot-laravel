<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Push\Delivery;

use Illuminate\Support\Facades\DB;
use MarinSolutions\CheckybotLaravel\Domain\Push\Models\PushOperation;

final class PushPayloadFactory
{
    /** @return array<string, mixed> */
    public function make(PushOperation $operation): array
    {
        $payload = $operation->payload;
        $critical = $operation->severity === 'critical';
        $monitorIds = array_values(array_unique(array_filter(
            (array) ($payload['problem_filter']['monitor_uuids'] ?? []),
            static fn ($id): bool => is_string($id),
        )));
        $count = count($monitorIds);
        $phase = $operation->phase;
        $title = $phase === 'recovery' ? 'Monitors recovered' : ($critical ? 'Critical monitor incident' : 'Monitor warning');
        $body = $phase === 'recovery'
            ? sprintf('%d %s recovered.', $count, $count === 1 ? 'monitor has' : 'monitors have')
            : sprintf('%d %s need attention.', $count, $count === 1 ? 'monitor needs' : 'monitors');

        return [
            'title' => mb_substr($title, 0, 100),
            'body' => mb_substr($body, 0, 180),
            'data' => [
                'route' => 'problems',
                'projectUuid' => $operation->project_id,
                'groupId' => $operation->group_id,
                'monitorUuids' => $monitorIds,
                'phase' => $phase,
                'severity' => $operation->severity,
                'threadKey' => $operation->thread_key,
                'refreshWidget' => true,
            ],
            'priority' => $critical ? 'high' : 'default',
            'sound' => $critical ? 'default' : null,
            'interruptionLevel' => $critical ? 'time-sensitive' : 'passive',
            'badge' => $this->problemCount($operation->project_id),
            '_contentAvailable' => true,
        ];
    }

    private function problemCount(string $projectId): int
    {
        return (int) DB::table('monitor_states')
            ->where('project_id', $projectId)
            ->whereIn('state', ['warn', 'down', 'recovering'])
            ->count();
    }
}
