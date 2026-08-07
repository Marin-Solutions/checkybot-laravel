<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Http\Controllers\WebDashboard;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;

final class HarnessWebOperatorController
{
    public function __invoke(Request $request, string $projectUuid): JsonResponse
    {
        abort_unless(app()->environment(['testing', 'harness']), 404);
        abort_unless(Uuid::isValid($projectUuid), 404);

        $projectUuid = strtolower($projectUuid);
        $request->session()->put(AuthenticateWebOperator::HARNESS_PROJECT_SESSION_KEY, $projectUuid);
        $request->session()->regenerate();

        return new JsonResponse([
            'authenticated' => true,
            'project_uuid' => $projectUuid,
            'dashboard_url' => '/checkybot',
        ]);
    }
}
