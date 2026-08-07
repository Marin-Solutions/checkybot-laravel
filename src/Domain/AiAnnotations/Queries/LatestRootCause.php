<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Queries;

use Illuminate\Support\Facades\Schema;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiIncidentAnnotation;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorIdentity;
use MarinSolutions\CheckybotLaravel\Models\MonitorTransition;

final class LatestRootCause
{
    public function forMonitor(MonitorIdentity $identity): ?string
    {
        if (! Schema::hasTable('ai_incident_annotations')) {
            return null;
        }

        $transitionOperationId = MonitorTransition::query()
            ->where('project_id', $identity->projectId)
            ->where('monitor_id', $identity->monitorId)
            ->where('monitor_type', $identity->type->value)
            ->where('to_state', 'down')
            ->orderByDesc('occurred_at')
            ->orderByDesc('operation_sequence')
            ->value('operation_id');
        if (! is_string($transitionOperationId)) {
            return null;
        }

        return AiIncidentAnnotation::query()
            ->forProject($identity->projectId)
            ->where('transition_operation_id', $transitionOperationId)
            ->value('root_cause');
    }
}
