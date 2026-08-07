<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent\Data;

final readonly class MetricCandidate
{
    /** @param array<string, mixed> $detail */
    public function __construct(
        public string $signal,
        public int $bandValue,
        public string $reasonCode,
        public array $detail,
    ) {}
}
