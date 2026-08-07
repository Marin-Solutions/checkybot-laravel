<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent\Data;

final readonly class ServerEvaluation
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public string $signal,
        public int $bandValue,
        public string $reasonCode,
        public array $details,
    ) {}
}
