<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts;

final class FoundationContract
{
    public const VERSION = 'monitor-foundation.v1';

    public const CHECK_SYNC_VERSION = 'check-sync.v1';

    /** @return list<string> */
    public static function eventTypes(): array
    {
        return ['monitor.transitioned', 'contract.check_sync.probed', 'incident.redaction.probed'];
    }
}
