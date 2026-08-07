<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts;

use Carbon\CarbonImmutable;

final readonly class StatusSummary
{
    /**
     * @param  array{servers: array{healthy: int, warn: int, down: int}, websites: array{healthy: int, warn: int, down: int}, apis: array{healthy: int, warn: int, down: int}}  $counts
     */
    public function __construct(
        public array $counts,
        public ?CarbonImmutable $updatedAt,
        public bool $stale,
    ) {}
}
