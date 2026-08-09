<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Http\Controllers\WebDashboard;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Support\MaintenanceSilencer;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Queries\StatusSummaryQuery;
use MarinSolutions\CheckybotLaravel\Domain\Security\Foundation\RecursiveRedactor;
use Symfony\Component\HttpFoundation\Response;

final readonly class OverviewController
{
    public function __construct(
        private CurrentProjectResolver $projects,
        private StatusSummaryQuery $summaries,
        private ProblemProjection $problemProjection,
        private MaintenanceSilencer $maintenance,
        private RecursiveRedactor $redactor,
        private InertiaPage $inertia,
    ) {}

    public function __invoke(Request $request): Response
    {
        $project = $this->projects->resolve($request);

        try {
            $filters = DashboardFilters::fromRequest($request);
        } catch (ValidationException $exception) {
            return $this->inertia->validation($exception->errors());
        }

        $summary = $this->summaries->forProject($project->uuid);
        $projection = $this->problemProjection->forProject($project, $filters);
        $maintenance = $this->maintenance->currentForProject($project->uuid);
        $reason = $maintenance?->reason;

        return $this->inertia->render($request, 'CheckybotDashboard/Overview', [
            'filters' => $filters->toArray(),
            'summary' => [
                'counts' => $summary->counts,
                'updated_at' => $summary->updatedAt?->toRfc3339String(),
                'stale' => $summary->stale,
            ],
            'problems' => $projection['problems'],
            'pagination' => $projection['pagination'],
            'maintenance' => [
                'reason' => is_string($reason) ? (string) $this->redactor->redact($reason, 'reason') : null,
                'ends_at' => $maintenance?->ends_at->toRfc3339String(),
                'silenced' => $maintenance !== null,
                'effective_scope' => $maintenance?->scope,
            ],
        ]);
    }
}
