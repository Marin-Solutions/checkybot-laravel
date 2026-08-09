<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts;

use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorIdentity;

interface PullRecheckProducer
{
    public function request(MonitorIdentity $identity, int $attemptNumber, string $requestId): void;
}
