<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Support;

use Illuminate\Database\QueryException;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Models\ExpandedCheckEvaluation;

trait ReservesExpandedEvaluation
{
    /** @param array<string, mixed> $attributes @return array{ExpandedCheckEvaluation, bool} */
    private function reserve(string $operationId, array $attributes): array
    {
        try {
            $evaluation = new ExpandedCheckEvaluation;
            $evaluation->forceFill(['operation_id' => $operationId, ...$attributes])->save();

            return [$evaluation, true];
        } catch (QueryException $exception) {
            $evaluation = ExpandedCheckEvaluation::query()->where('operation_id', $operationId)->first();
            if ($evaluation === null) {
                throw $exception;
            }

            return [$evaluation, false];
        }
    }
}
