<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts;

use InvalidArgumentException;

final readonly class NormalizedThresholds
{
    public function __construct(
        public float $warn,
        public float $critical,
        public float $recoveryDelta = 5.0,
    ) {
        if (! is_finite($warn) || ! is_finite($critical) || ! is_finite($recoveryDelta)) {
            throw new InvalidArgumentException('Push thresholds must be finite numbers.');
        }
        if ($warn >= $critical) {
            throw new InvalidArgumentException('The warn threshold must be lower than the critical threshold.');
        }
        if ($recoveryDelta < 0) {
            throw new InvalidArgumentException('The recovery delta must be non-negative.');
        }
    }

    /** @return array{warn: float, critical: float, recovery_delta: float} */
    public function toArray(): array
    {
        return ['warn' => $this->warn, 'critical' => $this->critical, 'recovery_delta' => $this->recoveryDelta];
    }
}
