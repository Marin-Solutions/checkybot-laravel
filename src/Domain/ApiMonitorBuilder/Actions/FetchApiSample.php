<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Actions;

use Illuminate\Validation\ValidationException;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\BoundedHttpClient;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\BuilderInputValidator;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Data\AuthorizedApiMonitor;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\EndpointPolicy;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Exceptions\ConfigurationConflict;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Exceptions\UnsafeEndpoint;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\JsonPathCatalog;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Models\ApiMonitorConfiguration;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Models\ApiMonitorHeader;
use MarinSolutions\CheckybotLaravel\Domain\Security\Foundation\EncryptedHeaderValue;

final readonly class FetchApiSample
{
    public function __construct(
        private BuilderInputValidator $validator,
        private EndpointPolicy $endpoints,
        private BoundedHttpClient $http,
        private JsonPathCatalog $paths,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{mode: 'sample', status: int, latency_ms: int, json: mixed, paths: list<array{path: string, inferred_type: string, preview: string}>}
     */
    public function execute(AuthorizedApiMonitor $monitor, array $payload): array
    {
        $input = $this->validator->validate($payload, false);
        try {
            $this->endpoints->guard($input->endpoint);
        } catch (UnsafeEndpoint $exception) {
            throw ValidationException::withMessages(['endpoint' => [$exception->getMessage()]]);
        }

        /** @var ApiMonitorConfiguration|null $configuration */
        $configuration = ApiMonitorConfiguration::query()
            ->where('project_id', $monitor->identity->projectId)
            ->where('monitor_id', $monitor->identity->monitorId)
            ->first();
        $currentVersion = $configuration instanceof ApiMonitorConfiguration ? $configuration->version : 1;
        if ($currentVersion !== $input->configurationVersion) {
            throw new ConfigurationConflict;
        }

        /** @var array<string, ApiMonitorHeader> $stored */
        $stored = $configuration instanceof ApiMonitorConfiguration
            ? $configuration->headers()->get()->keyBy('normalized_name')->all()
            : [];
        $headers = [];
        foreach ($input->headers as $index => $mutation) {
            if ($mutation['action'] === 'remove') {
                continue;
            }
            if ($mutation['action'] === 'set') {
                $headers[$mutation['name']] = $mutation['value'];

                continue;
            }
            $encrypted = $stored[$mutation['normalized_name']] ?? null;
            if ($encrypted === null) {
                throw ValidationException::withMessages([
                    'headers.'.$index.'.action' => ['A stored encrypted value is required for preserve.'],
                ]);
            }
            $headers[$mutation['name']] = EncryptedHeaderValue::restore($encrypted->encrypted_value)->reveal();
        }

        $response = $this->http->fetch($input->endpoint, $input->method, $headers, $input->requestBody);
        $catalog = $this->paths->build($response->json);

        return [
            'mode' => 'sample',
            'status' => $response->status,
            'latency_ms' => $response->latencyMs,
            'json' => $catalog['json'],
            'paths' => $catalog['paths'],
        ];
    }
}
