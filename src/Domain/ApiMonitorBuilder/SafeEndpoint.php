<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder;

final readonly class SafeEndpoint
{
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public ?string $pinnedAddress,
    ) {}
}
