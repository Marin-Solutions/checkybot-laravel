<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts;

enum Severity: string
{
    case Warn = 'warn';
    case Critical = 'critical';
}
