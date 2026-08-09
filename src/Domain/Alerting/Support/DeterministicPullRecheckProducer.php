<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Support;

use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\PullRecheckProducer;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorIdentity;

final class DeterministicPullRecheckProducer implements PullRecheckProducer
{
    /** @var list<array<string, mixed>> */
    private array $requests = [];

    public function request(MonitorIdentity $identity, int $attemptNumber, string $requestId): void
    {
        $this->requests[] = [
            'identity' => $identity->toArray(),
            'attempt_number' => $attemptNumber,
            'request_id' => $requestId,
            'requested_at' => now()->toRfc3339String(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function requests(): array
    {
        return $this->requests;
    }
}
