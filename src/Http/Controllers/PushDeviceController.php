<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MarinSolutions\CheckybotLaravel\Domain\Push\Actions\DeactivatePushDevice;
use MarinSolutions\CheckybotLaravel\Domain\Push\Actions\RegisterPushDevice;
use MarinSolutions\CheckybotLaravel\Domain\Push\Contracts\PushProjectAuthorizer;
use MarinSolutions\CheckybotLaravel\Domain\Push\Data\RegisterPushDeviceData;
use Symfony\Component\HttpFoundation\Response;

final readonly class PushDeviceController
{
    public function __construct(
        private RegisterPushDevice $register,
        private DeactivatePushDevice $deactivate,
        private PushProjectAuthorizer $projects,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'installation_id' => ['required', 'uuid'],
            'expo_push_token' => ['required', 'string', 'regex:/^(ExponentPushToken|ExpoPushToken)\\[[A-Za-z0-9_-]+\\]$/', 'max:255'],
            'platform' => ['required', 'in:ios,android'],
            'project_uuid' => ['required', 'uuid'],
            'permission' => ['required', 'in:granted,provisional'],
            'app_version' => ['required', 'string', 'max:64', 'regex:/^\d+(?:\.\d+){0,3}(?:[-+][0-9A-Za-z.-]+)?$/'],
        ]);
        $user = $request->user();
        abort_unless($user instanceof Authenticatable, 401, 'Unauthenticated.');

        if (! $this->projects->canAccess($user, $validated['project_uuid'])) {
            return new JsonResponse(['message' => 'The user cannot register a device for this project.'], 403);
        }

        $result = $this->register->execute($user, new RegisterPushDeviceData(
            $validated['installation_id'],
            $validated['expo_push_token'],
            $validated['platform'],
            $validated['project_uuid'],
            $validated['permission'],
            $validated['app_version'],
        ));

        return new JsonResponse(['data' => $this->resource($result->device)], $result->created ? 201 : 200);
    }

    public function destroy(Request $request, string $pushDevice): Response
    {
        $user = $request->user();
        abort_unless($user instanceof Authenticatable, 401, 'Unauthenticated.');

        if (! $this->deactivate->execute($user, $pushDevice)) {
            return new JsonResponse(['message' => 'Push device not found.'], 404);
        }

        return response()->noContent();
    }

    private function resource($device): array
    {
        return [
            'id' => $device->public_id,
            'installation_id' => $device->installation_id,
            'platform' => $device->platform,
            'project_uuid' => $device->project_id,
            'active' => $device->active,
            'registered_at' => $device->registered_at->toRfc3339String(),
        ];
    }
}
