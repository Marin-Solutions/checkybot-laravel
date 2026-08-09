<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Actions\ClearMaintenanceMode;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Actions\CreateMaintenanceMode;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Data\CreateMaintenanceData;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Data\MaintenanceActor;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Exceptions\ActiveMaintenanceModeExists;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Exceptions\ImmutableMaintenanceOperation;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Http\AuthenticateMaintenanceActor;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Models\MaintenanceMode;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Support\MaintenanceSilencer;
use MarinSolutions\CheckybotLaravel\Domain\Security\Foundation\RecursiveRedactor;
use MarinSolutions\CheckybotLaravel\Policies\MaintenanceModePolicy;
use Symfony\Component\HttpFoundation\Response;

final readonly class MaintenanceModeController
{
    public function __construct(
        private CreateMaintenanceMode $create,
        private ClearMaintenanceMode $clear,
        private MaintenanceSilencer $silencer,
        private MaintenanceModePolicy $policy,
        private RecursiveRedactor $redactor,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'scope' => ['required', 'in:project,global'],
            'project_uuid' => ['nullable', 'uuid'],
            'duration_minutes' => ['required', 'integer', 'between:1,1440'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);
        $actor = $this->actor($request);
        $projectId = $this->authorizeStore($request, $actor, $validated['scope'], $validated['project_uuid'] ?? null);

        try {
            $result = $this->create->execute(new CreateMaintenanceData(
                $validated['operation_id'],
                $validated['scope'],
                $projectId,
                (int) $validated['duration_minutes'],
                isset($validated['reason']) ? (string) $this->redactor->redact($validated['reason'], 'reason') : null,
            ));
        } catch (ActiveMaintenanceModeExists $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 409);
        } catch (ImmutableMaintenanceOperation $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 409);
        }

        return new JsonResponse(
            ['data' => $this->resource($result->mode)],
            $result->created ? 201 : 200,
        );
    }

    public function current(Request $request): JsonResponse
    {
        $validated = $request->validate(['project_uuid' => ['nullable', 'uuid']]);
        $actor = $this->actor($request);

        if ($actor->token !== null) {
            if (! $actor->token->allows('maintenance:read') || $request->exists('project_uuid')) {
                return $this->forbidden('The caller cannot read maintenance state for this project.');
            }
            $projectId = $actor->token->project_id;
            $mode = $this->silencer->currentForProject($projectId);
        } else {
            $projectId = $validated['project_uuid'] ?? null;
            if (! $this->policy->read($actor->operator, $projectId)) {
                return $this->forbidden('The caller cannot read maintenance state for this project.');
            }
            $mode = $projectId === null
                ? $this->silencer->currentGlobal()
                : $this->silencer->currentForProject($projectId);
        }

        return new JsonResponse(['data' => [
            'silenced' => $mode !== null,
            'effective_scope' => $mode?->scope,
            'maintenance_mode_id' => $mode?->public_id,
            'ends_at' => $mode?->ends_at->toRfc3339String(),
            'reason' => $mode?->reason,
        ]]);
    }

    public function destroy(Request $request, string $maintenanceMode): Response
    {
        $actor = $this->actor($request);
        $mode = MaintenanceMode::query()->where('public_id', $maintenanceMode)->first();
        if ($mode === null) {
            return new JsonResponse(['message' => 'Maintenance mode not found.'], 404);
        }

        if ($actor->token !== null) {
            $authorized = $actor->token->allows('maintenance:write')
                && $mode->scope === 'project'
                && hash_equals($actor->token->project_id, (string) $mode->project_id);
        } else {
            $authorized = $this->policy->write($actor->operator, $mode->scope, $mode->project_id);
        }
        if (! $authorized) {
            return $this->forbidden('The caller cannot clear this maintenance mode.');
        }

        $this->clear->execute($mode);

        return response()->noContent();
    }

    private function authorizeStore(Request $request, MaintenanceActor $actor, string $scope, ?string $requestedProjectId): ?string
    {
        if ($actor->token !== null) {
            if (! $actor->token->allows('maintenance:write')
                || $scope !== 'project'
                || $request->exists('project_uuid')) {
                abort(403, 'This token or operator cannot manage the requested maintenance scope.');
            }

            return $actor->token->project_id;
        }

        if ($scope === 'project' && $requestedProjectId === null) {
            throw ValidationException::withMessages(['project_uuid' => 'The project_uuid field is required for project scope.']);
        }
        if ($scope === 'global' && $requestedProjectId !== null) {
            throw ValidationException::withMessages(['project_uuid' => 'The project_uuid field is prohibited for global scope.']);
        }
        if (! $this->policy->write($actor->operator, $scope, $requestedProjectId)) {
            abort(403, 'This token or operator cannot manage the requested maintenance scope.');
        }

        return $scope === 'project' ? $requestedProjectId : null;
    }

    private function actor(Request $request): MaintenanceActor
    {
        $actor = $request->attributes->get(AuthenticateMaintenanceActor::REQUEST_ATTRIBUTE);
        abort_unless($actor instanceof MaintenanceActor, 401, 'Unauthenticated.');

        return $actor;
    }

    /** @return array<string, mixed> */
    private function resource(MaintenanceMode $mode): array
    {
        return [
            'id' => $mode->public_id,
            'scope' => $mode->scope,
            'project_uuid' => $mode->project_id,
            'starts_at' => $mode->starts_at->toRfc3339String(),
            'ends_at' => $mode->ends_at->toRfc3339String(),
            'active' => $mode->isActiveAt(CarbonImmutable::instance(now())),
        ];
    }

    private function forbidden(string $message): JsonResponse
    {
        return new JsonResponse(['message' => $message], 403);
    }
}
