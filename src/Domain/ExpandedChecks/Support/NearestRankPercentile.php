<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Support;

use InvalidArgumentException;

final class NearestRankPercentile
{
    /** @param list<float|int> $values */
    public function calculate(array $values, float $percentile): float
    {
        $finite = array_values(array_map('floatval', array_filter($values, static fn (float|int $value): bool => is_finite((float) $value))));
        if ($finite === [] || $percentile <= 0 || $percentile > 1) {
            throw new InvalidArgumentException('Nearest-rank percentile requires finite values and a percentile in (0, 1].');
        }
        sort($finite, SORT_NUMERIC);
        $rank = (int) ceil($percentile * count($finite));

        return $finite[$rank - 1];
    }
}
