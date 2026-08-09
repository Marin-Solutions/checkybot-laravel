<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Actions;

use Illuminate\Http\Request;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Data\AuthorizedApiMonitor;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorIdentity;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorType;
use MarinSolutions\CheckybotLaravel\Http\Controllers\WebDashboard\CurrentProjectResolver;
use MarinSolutions\CheckybotLaravel\Models\MonitorState;
use Ramsey\Uuid\Uuid;

final readonly class AuthorizeApiMonitor
{
    public function __construct(private CurrentProjectResolver $projects) {}

    public function forRequest(Request $request, string $monitorUuid): AuthorizedApiMonitor
    {
        $project = $this->projects->resolve($request);
        if (! Uuid::isValid($monitorUuid)) {
            abort(404, 'API monitor not found.');
        }
        $monitorUuid = strtolower($monitorUuid);
        $authorized = MonitorState::query()
            ->where('project_id', $project->uuid)
            ->where('monitor_id', $monitorUuid)
            ->where('monitor_type', MonitorType::Api->value)
            ->exists();
        if (! $authorized) {
            $wrongTypeInProject = MonitorState::query()
                ->where('project_id', $project->uuid)
                ->where('monitor_id', $monitorUuid)
                ->exists();
            if ($wrongTypeInProject) {
                abort(404, 'API monitor not found.');
            }
            $foreign = MonitorState::query()
                ->where('monitor_id', $monitorUuid)
                ->where('monitor_type', MonitorType::Api->value)
                ->where('project_id', '!=', $project->uuid)
                ->exists();
            abort($foreign ? 403 : 404, $foreign ? 'The operator cannot manage this API monitor.' : 'API monitor not found.');
        }

        return new AuthorizedApiMonitor(new MonitorIdentity($project->uuid, $monitorUuid, MonitorType::Api));
    }
}
