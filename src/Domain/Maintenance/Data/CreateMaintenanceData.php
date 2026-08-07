<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Maintenance\Data;

final readonly class CreateMaintenanceData
{
    public function __construct(
        public string $operationId,
        public string $scope,
        public ?string $projectId,
        public int $durationMinutes,
        public ?string $reason,
    ) {}

    public function payloadHash(): string
    {
        return hash('sha256', json_encode([
            'operation_id' => $this->operationId,
            'scope' => $this->scope,
            'project_uuid' => $this->projectId,
            'duration_minutes' => $this->durationMinutes,
            'reason' => $this->reason,
        ], JSON_THROW_ON_ERROR));
    }
}
