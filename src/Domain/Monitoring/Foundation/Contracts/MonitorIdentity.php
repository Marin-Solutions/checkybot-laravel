<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

final readonly class MonitorIdentity
{
    public function __construct(
        public string $projectId,
        public string $monitorId,
        public MonitorType $type,
    ) {
        if (! Uuid::isValid($projectId) || ! Uuid::isValid($monitorId)) {
            throw new InvalidArgumentException('Monitor identities require UUID project and monitor identifiers.');
        }
    }

    /** @return array{project_id: string, monitor_id: string, type: string} */
    public function toArray(): array
    {
        return ['project_id' => $this->projectId, 'monitor_id' => $this->monitorId, 'type' => $this->type->value];
    }
}
