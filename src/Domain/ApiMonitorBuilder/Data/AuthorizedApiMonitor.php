<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Data;

use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorIdentity;

final readonly class AuthorizedApiMonitor
{
    public function __construct(public MonitorIdentity $identity) {}
}
