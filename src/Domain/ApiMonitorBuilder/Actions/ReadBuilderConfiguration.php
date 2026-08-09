<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Actions;

use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Data\AuthorizedApiMonitor;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Models\ApiMonitorAssertion;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Models\ApiMonitorConfiguration;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Models\ApiMonitorHeader;
use MarinSolutions\CheckybotLaravel\Domain\Security\Foundation\EncryptedHeaderValue;

final class ReadBuilderConfiguration
{
    public function configuration(AuthorizedApiMonitor $monitor): ApiMonitorConfiguration
    {
        return ApiMonitorConfiguration::query()->firstOrCreate(
            [
                'project_id' => $monitor->identity->projectId,
                'monitor_id' => $monitor->identity->monitorId,
            ],
            [
                'method' => 'GET',
                'endpoint' => 'https://example.com/',
                'version' => 1,
            ],
        );
    }

    /**
     * @return array{
     *   endpoint: string,
     *   method: string,
     *   headers: list<array{name: string, mask: string, has_value: bool}>,
     *   assertions: list<array<string, mixed>>,
     *   version: int
     * }
     */
    public function safeArray(ApiMonitorConfiguration $configuration): array
    {
        $headers = $configuration->headers()->get()->map(
            static fn (ApiMonitorHeader $header): array => [
                'name' => $header->name,
                'mask' => (string) EncryptedHeaderValue::restore($header->encrypted_value),
                'has_value' => true,
            ],
        )->values()->all();
        $assertions = $configuration->assertions()->get()->map(
            fn (ApiMonitorAssertion $assertion): array => $this->assertionArray($assertion),
        )->values()->all();

        return [
            'endpoint' => $configuration->endpoint,
            'method' => $configuration->method,
            'headers' => $headers,
            'assertions' => $assertions,
            'version' => $configuration->version,
        ];
    }

    /** @return array<string, mixed> */
    private function assertionArray(ApiMonitorAssertion $assertion): array
    {
        $result = [
            'kind' => $assertion->kind,
            'operator' => $assertion->operator,
        ];
        if ($assertion->json_path !== null) {
            $result['path'] = $assertion->json_path;
        }
        if ($assertion->has_expected) {
            $result['expected'] = json_decode((string) $assertion->expected_value, true, 64, JSON_THROW_ON_ERROR);
        }

        return $result;
    }
}
