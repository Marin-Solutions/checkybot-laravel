<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts;

enum MonitorType: string
{
    case Server = 'server';
    case Website = 'website';
    case Api = 'api';
}
