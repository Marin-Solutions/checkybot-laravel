<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts;

use RuntimeException;

final class HeartbeatTransportException extends RuntimeException
{
    private function __construct(public readonly string $reason)
    {
        parent::__construct('The watchdog heartbeat could not be delivered.');
    }

    public static function timeout(): self
    {
        return new self('timeout');
    }

    public static function transport(): self
    {
        return new self('transport');
    }
}
