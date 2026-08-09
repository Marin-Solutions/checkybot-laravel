<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts;

interface HeartbeatClient
{
    public function get(string $url, int $timeoutSeconds, string $userAgent): HeartbeatResponse;
}
