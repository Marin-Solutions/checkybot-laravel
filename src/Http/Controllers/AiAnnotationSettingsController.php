<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Actions\ReadAiSettings;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Actions\UpdateAiSettings;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Exceptions\StaleAiSettings;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Support\AiConfiguration;
use MarinSolutions\CheckybotLaravel\Http\Controllers\WebDashboard\AuthorizedProject;
use MarinSolutions\CheckybotLaravel\Http\Controllers\WebDashboard\CurrentProjectResolver;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final readonly class AiAnnotationSettingsController
{
    public function __construct(
        private CurrentProjectResolver $projects,
        private ReadAiSettings $read,
        private UpdateAiSettings $update,
        private AiConfiguration $configuration,
    ) {}

    public function show(Request $request): JsonResponse
    {
        if ($request->query->count() !== 0) {
            throw ValidationException::withMessages(['query' => 'AI annotation settings do not accept query parameters.']);
        }

        $project = $this->project($request);

        return response()->json(['data' => $this->read->forProject($project->uuid)->toArray()]);
    }

    public function update(Request $request): JsonResponse
    {
        $unknown = array_values(array_diff(array_keys($request->all()), ['enabled', 'version']));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                $unknown[0] => 'Unknown fields are not accepted.',
            ]);
        }

        $validated = validator($request->all(), [
            'enabled' => ['required', 'boolean'],
            'version' => ['required', 'integer', 'min:0'],
        ])->validate();
        if ((bool) $validated['enabled'] && ! $this->configuration->canEnable()) {
            throw ValidationException::withMessages([
                'enabled' => 'AI annotations require an HTTPS provider and positive global and project budget caps.',
                'provider_configuration' => 'The AI annotation provider configuration is invalid.',
            ]);
        }

        $project = $this->project($request);
        try {
            $data = $this->update->execute($project->uuid, (bool) $validated['enabled'], (int) $validated['version']);
        } catch (StaleAiSettings) {
            return response()->json(['message' => 'AI annotation settings changed; reload before saving.'], 409);
        }

        return response()->json(['data' => $data->toArray()]);
    }

    private function project(Request $request): AuthorizedProject
    {
        try {
            return $this->projects->resolve($request);
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() === 403) {
                abort(403, 'The operator cannot manage AI annotations for the selected project.');
            }
            throw $exception;
        }
    }
}
