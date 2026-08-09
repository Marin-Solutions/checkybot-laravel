<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Data;

use Carbon\CarbonImmutable;

final readonly class SpeedObservation
{
    public function __construct(public float $milliseconds, public CarbonImmutable $observedAt) {}
}
