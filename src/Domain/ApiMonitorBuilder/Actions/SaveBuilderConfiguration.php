<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\BuilderInputValidator;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Data\AuthorizedApiMonitor;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\EndpointPolicy;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Exceptions\ConfigurationConflict;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Exceptions\UnsafeEndpoint;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Models\ApiMonitorAssertion;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Models\ApiMonitorConfiguration;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Models\ApiMonitorHeader;
use MarinSolutions\CheckybotLaravel\Domain\Security\Foundation\EncryptedHeaderValue;

final readonly class SaveBuilderConfiguration
{
    public function __construct(
        private BuilderInputValidator $validator,
        private EndpointPolicy $endpoints,
        private ReadBuilderConfiguration $reader,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{endpoint: string, method: string, headers: list<array{name: string, mask: string, has_value: bool}>, assertions: list<array<string, mixed>>, version: int}
     */
    public function execute(AuthorizedApiMonitor $monitor, array $payload): array
    {
        $input = $this->validator->validate($payload, true);
        try {
            $this->endpoints->guard($input->endpoint);
        } catch (UnsafeEndpoint $exception) {
            throw ValidationException::withMessages(['endpoint' => [$exception->getMessage()]]);
        }

        /** @var ApiMonitorConfiguration $saved */
        $saved = DB::transaction(function () use ($monitor, $input): ApiMonitorConfiguration {
            /** @var ApiMonitorConfiguration|null $configuration */
            $configuration = ApiMonitorConfiguration::query()
                ->where('project_id', $monitor->identity->projectId)
                ->where('monitor_id', $monitor->identity->monitorId)
                ->lockForUpdate()
                ->first();
            if ($configuration === null) {
                if ($input->configurationVersion !== 1) {
                    throw new ConfigurationConflict;
                }
                $configuration = ApiMonitorConfiguration::query()->create([
                    'project_id' => $monitor->identity->projectId,
                    'monitor_id' => $monitor->identity->monitorId,
                    'method' => 'GET',
                    'endpoint' => 'https://example.com/',
                    'version' => 1,
                ]);
            }
            if ($configuration->version !== $input->configurationVersion) {
                throw new ConfigurationConflict;
            }

            /** @var array<string, ApiMonitorHeader> $existing */
            $existing = $configuration->headers()->get()->keyBy('normalized_name')->all();
            $planned = [];
            $removed = [];
            foreach ($input->headers as $index => $mutation) {
                $normalized = $mutation['normalized_name'];
                if ($mutation['action'] === 'remove') {
                    $removed[$normalized] = true;

                    continue;
                }
                if ($mutation['action'] === 'preserve') {
                    if (! isset($existing[$normalized])) {
                        throw ValidationException::withMessages([
                            'headers.'.$index.'.action' => ['A stored encrypted value is required for preserve.'],
                        ]);
                    }
                    $ciphertext = $existing[$normalized]->encrypted_value;
                } else {
                    $ciphertext = EncryptedHeaderValue::encrypt($mutation['value'])->ciphertext();
                }
                $planned[$normalized] = [
                    'name' => $mutation['name'],
                    'ciphertext' => $ciphertext,
                ];
            }
            foreach ($existing as $normalized => $header) {
                if (! isset($planned[$normalized]) && ! isset($removed[$normalized])) {
                    $planned[$normalized] = ['name' => $header->name, 'ciphertext' => $header->encrypted_value];
                }
            }

            $updated = ApiMonitorConfiguration::query()
                ->whereKey($configuration->getKey())
                ->where('version', $input->configurationVersion)
                ->update([
                    'endpoint' => $input->endpoint,
                    'method' => $input->method,
                    'version' => $input->configurationVersion + 1,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new ConfigurationConflict;
            }

            ApiMonitorHeader::query()->where('configuration_id', $configuration->getKey())
                ->update(['position' => DB::raw('position + 1000')]);
            foreach (array_values($planned) as $position => $header) {
                ApiMonitorHeader::query()->updateOrCreate(
                    [
                        'configuration_id' => $configuration->getKey(),
                        'normalized_name' => strtolower($header['name']),
                    ],
                    [
                        'name' => $header['name'],
                        'encrypted_value' => $header['ciphertext'],
                        'position' => $position,
                    ],
                );
            }
            $plannedNames = array_keys($planned);
            $headerDelete = ApiMonitorHeader::query()->where('configuration_id', $configuration->getKey());
            if ($plannedNames === []) {
                $headerDelete->delete();
            } else {
                $headerDelete->whereNotIn('normalized_name', $plannedNames)->delete();
            }

            ApiMonitorAssertion::query()->where('configuration_id', $configuration->getKey())->delete();
            foreach ($input->assertions as $position => $assertion) {
                $hasExpected = array_key_exists('expected', $assertion);
                ApiMonitorAssertion::query()->create([
                    'configuration_id' => $configuration->getKey(),
                    'kind' => $assertion['kind'],
                    'operator' => $assertion['operator'],
                    'json_path' => $assertion['path'] ?? null,
                    'expected_value' => $hasExpected ? json_encode($assertion['expected'], JSON_THROW_ON_ERROR) : null,
                    'has_expected' => $hasExpected,
                    'position' => $position,
                ]);
            }

            return $configuration->fresh() ?? throw new ConfigurationConflict;
        }, 3);

        return $this->reader->safeArray($saved);
    }
}
