<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Http\Controllers\WebDashboard;

use Illuminate\Http\Request;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\AuthorizedMonitorIdentity;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Queries\IncidentTimelineReadModel;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorIdentity;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorType;
use MarinSolutions\CheckybotLaravel\Models\MonitorState;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;

final readonly class MonitorDetailController
{
    public function __construct(
        private CurrentProjectResolver $projects,
        private IncidentTimelineReadModel $timelines,
        private InertiaPage $inertia,
    ) {}

    public function __invoke(Request $request, string $type, string $monitorUuid): Response
    {
        $project = $this->projects->resolve($request);
        $monitorType = MonitorType::tryFrom($type);
        if ($monitorType === null || ! Uuid::isValid($monitorUuid)) {
            abort(404);
        }
        $monitorUuid = strtolower($monitorUuid);

        $currentProjectMonitor = MonitorState::query()
            ->forProject($project->uuid)
            ->where('monitor_id', $monitorUuid)
            ->where('monitor_type', $monitorType->value)
            ->first();
        if ($currentProjectMonitor === null && MonitorState::query()
            ->forProject($project->uuid)
            ->where('monitor_id', $monitorUuid)
            ->exists()) {
            abort(404);
        }
        if ($currentProjectMonitor === null) {
            $foreignExists = MonitorState::query()
                ->where('monitor_id', $monitorUuid)
                ->where('monitor_type', $monitorType->value)
                ->where('project_id', '!=', $project->uuid)
                ->exists();
            abort($foreignExists ? 403 : 404, $foreignExists ? 'The operator cannot read this project.' : 'Monitor not found.');
        }

        $identity = new MonitorIdentity($project->uuid, $monitorUuid, $monitorType);
        $timeline = $this->timelines->forMonitor(new AuthorizedMonitorIdentity($identity, $project->uuid));
        abort_if($timeline === null, 404);

        return $this->inertia->render($request, 'CheckybotDashboard/MonitorDetail', [
            'monitor' => [
                'identity' => $identity->toArray(),
                'entered_at' => $timeline['entered_at'],
                'current_state' => $timeline['current_state'],
            ],
            'timeline' => [
                'transitions' => $timeline['transitions'],
                'incident_groups' => $timeline['incident_groups'],
                'annotation_slots' => $timeline['annotation_slots'],
            ],
        ]);
    }
}
