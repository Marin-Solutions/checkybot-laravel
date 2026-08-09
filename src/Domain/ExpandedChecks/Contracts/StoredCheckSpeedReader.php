<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Contracts;

use Carbon\CarbonImmutable;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Data\SpeedObservation;

interface StoredCheckSpeedReader
{
    /** @return list<SpeedObservation> */
    public function successfulFiniteFor(string $projectId, string $checkId, CarbonImmutable $notBefore, int $limit): array;
}
