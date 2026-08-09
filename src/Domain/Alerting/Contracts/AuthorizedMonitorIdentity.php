<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts;

use InvalidArgumentException;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorIdentity;
use Ramsey\Uuid\Uuid;

final readonly class AuthorizedMonitorIdentity
{
    public function __construct(
        public MonitorIdentity $identity,
        public string $authorizedProjectId,
    ) {
        if (! Uuid::isValid($authorizedProjectId)) {
            throw new InvalidArgumentException('The authorization project must be a UUID.');
        }
    }
}
