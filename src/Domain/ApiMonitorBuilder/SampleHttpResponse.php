<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder;

final readonly class SampleHttpResponse
{
    public function __construct(
        public int $status,
        public int $latencyMs,
        public mixed $json,
    ) {}
}
