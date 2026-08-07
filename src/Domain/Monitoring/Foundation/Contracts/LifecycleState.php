<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts;

enum LifecycleState: string
{
    case Healthy = 'healthy';
    case Warn = 'warn';
    case Down = 'down';
    case Recovering = 'recovering';
}
