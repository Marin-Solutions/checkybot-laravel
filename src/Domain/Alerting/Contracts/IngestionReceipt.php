<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts;

use Carbon\CarbonImmutable;

final readonly class IngestionReceipt
{
    public function __construct(
        public bool $accepted,
        public string $operationId,
        public string $status,
        public ?CarbonImmutable $nextRetryAt = null,
    ) {}

    /** @return array{accepted: bool, operation_id: string, status: string, next_retry_at: string|null} */
    public function toArray(): array
    {
        return [
            'accepted' => $this->accepted,
            'operation_id' => $this->operationId,
            'status' => $this->status,
            'next_retry_at' => $this->nextRetryAt?->toRfc3339String(),
        ];
    }
}
