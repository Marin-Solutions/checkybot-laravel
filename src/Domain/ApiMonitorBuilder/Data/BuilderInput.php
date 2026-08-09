<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Data;

final readonly class BuilderInput
{
    /**
     * @param  list<array{name: string, normalized_name: string, action: 'preserve'|'set'|'remove', value?: string}>  $headers
     * @param  list<array<string, mixed>>  $assertions
     */
    public function __construct(
        public string $endpoint,
        public string $method,
        public array $headers,
        public array $assertions,
        public int $configurationVersion,
        public mixed $requestBody = null,
    ) {}
}
