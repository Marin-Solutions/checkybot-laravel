<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent\Data;

final readonly class AgentReportReceipt
{
    /** @param list<string> $evaluationOperationIds */
    public function __construct(
        public string $operationId,
        public string $serverUuid,
        public string $status,
        public bool $created,
        public array $evaluationOperationIds,
    ) {}

    /** @return array{data:array{operation_id:string,status:string,server_uuid:string,evaluation_operation_ids:list<string>}} */
    public function toArray(): array
    {
        return ['data' => [
            'operation_id' => $this->operationId,
            'status' => $this->status,
            'server_uuid' => $this->serverUuid,
            'evaluation_operation_ids' => $this->evaluationOperationIds,
        ]];
    }
}
