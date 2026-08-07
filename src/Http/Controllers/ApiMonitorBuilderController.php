<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Actions\AuthorizeApiMonitor;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Actions\FetchApiSample;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Actions\ReadBuilderConfiguration;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Actions\SaveBuilderConfiguration;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Exceptions\ConfigurationConflict;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Exceptions\SampleFetchException;
use MarinSolutions\CheckybotLaravel\Http\Controllers\WebDashboard\InertiaPage;
use Symfony\Component\HttpFoundation\Response;

final readonly class ApiMonitorBuilderController
{
    public function __construct(
        private AuthorizeApiMonitor $authorize,
        private ReadBuilderConfiguration $reader,
        private SaveBuilderConfiguration $save,
        private FetchApiSample $sample,
        private InertiaPage $inertia,
    ) {}

    public function show(Request $request, string $monitorUuid): Response
    {
        $monitor = $this->authorize->forRequest($request, $monitorUuid);
        $configuration = $this->reader->configuration($monitor);

        return $this->inertia->render($request, 'CheckybotDashboard/ApiAssertionBuilder', [
            'monitor' => $monitor->identity->toArray(),
            'configuration' => $this->reader->safeArray($configuration),
            'sample' => null,
        ]);
    }

    public function update(Request $request, string $monitorUuid): JsonResponse
    {
        $monitor = $this->authorize->forRequest($request, $monitorUuid);
        try {
            return new JsonResponse(['data' => $this->save->execute($monitor, $request->all())]);
        } catch (ConfigurationConflict) {
            return new JsonResponse([
                'message' => 'The builder configuration changed; reload before saving.',
            ], 409);
        } catch (ValidationException $exception) {
            return $this->validation($exception);
        }
    }

    public function sample(Request $request, string $monitorUuid): JsonResponse
    {
        $monitor = $this->authorize->forRequest($request, $monitorUuid);
        try {
            return new JsonResponse(['data' => $this->sample->execute($monitor, $request->all())]);
        } catch (ConfigurationConflict) {
            return new JsonResponse([
                'message' => 'The builder configuration changed; reload before fetching another sample.',
                'manual_entry' => true,
            ], 409);
        } catch (ValidationException $exception) {
            return $this->validation($exception, true);
        } catch (SampleFetchException $exception) {
            return new JsonResponse([
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                    'upstream_status' => $exception->upstreamStatus,
                ],
                'manual_entry' => true,
            ], $exception->httpStatus);
        }
    }

    private function validation(ValidationException $exception, bool $manualEntry = false): JsonResponse
    {
        $response = [
            'message' => 'The given data was invalid.',
            'errors' => $exception->errors(),
        ];
        if ($manualEntry) {
            $response['manual_entry'] = true;
        }

        return new JsonResponse($response, 422);
    }
}
