<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Support;

use Carbon\CarbonImmutable;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Contracts\StoredCheckSpeedReader;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Data\SpeedObservation;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Models\StoredCheckSpeedSample;

final class EloquentStoredCheckSpeedReader implements StoredCheckSpeedReader
{
    public function successfulFiniteFor(string $projectId, string $checkId, CarbonImmutable $notBefore, int $limit): array
    {
        $samples = StoredCheckSpeedSample::query()
            ->where('project_id', $projectId)
            ->where('check_id', $checkId)
            ->where('successful', true)
            ->whereNotNull('speed_ms')
            ->where('observed_at', '>=', $notBefore)
            ->orderByDesc('observed_at')->orderByDesc('id')
            ->limit(max(1, min(1000, $limit)))
            ->get()
            ->reverse();

        $observations = [];
        foreach ($samples as $sample) {
            $milliseconds = (float) $sample->speed_ms;
            if (! is_finite($milliseconds) || $milliseconds < 0) {
                continue;
            }
            $observations[] = new SpeedObservation($milliseconds, $sample->observed_at);
        }

        return $observations;
    }
}
