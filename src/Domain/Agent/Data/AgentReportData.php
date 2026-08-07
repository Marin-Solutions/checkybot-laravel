<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent\Data;

use Carbon\CarbonImmutable;

final readonly class AgentReportData
{
    /**
     * @param  array{five_min_percent:int|float}  $cpu
     * @param  array{used_percent:int|float}  $memory
     * @param  list<array{mount:string,used_percent:int|float,predicted_days_to_full:int|float|null}>  $disks
     * @param  list<array{name:string,rx_bytes_total:int,tx_bytes_total:int,rx_delta_bytes:?int,tx_delta_bytes:?int,elapsed_seconds:int|float|null,sample_status:string}>  $networkInterfaces
     * @param  list<array{pool:string,active_workers:int,max_children:int,max_children_reached_5m:int}>  $phpFpmPools
     * @param  array{window_seconds:int,total_requests:int,five_xx_count:int,upstream_timeout_count:int}  $nginxWindow
     * @param  list<array{kind:string,path_hint:string,status:string}>  $prerequisites
     * @param  list<array{source:string,observed_at:string,line:string}>  $relevantLogLines
     * @param  array<string,mixed>  $canonicalPayload
     */
    public function __construct(
        public string $schemaVersion,
        public string $operationId,
        public string $agentVersion,
        public string $serverUuid,
        public CarbonImmutable $observedAt,
        public int $reportingIntervalSeconds,
        public array $cpu,
        public array $memory,
        public array $disks,
        public array $networkInterfaces,
        public array $phpFpmPools,
        public array $nginxWindow,
        public array $prerequisites,
        public array $relevantLogLines,
        public array $canonicalPayload,
    ) {}

    /** @param array<string,mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            schemaVersion: $validated['schema_version'],
            operationId: $validated['operation_id'],
            agentVersion: $validated['agent_version'],
            serverUuid: $validated['server_uuid'],
            observedAt: CarbonImmutable::parse($validated['observed_at'])->utc(),
            reportingIntervalSeconds: $validated['reporting_interval_seconds'],
            cpu: $validated['cpu'],
            memory: $validated['memory'],
            disks: $validated['disks'],
            networkInterfaces: $validated['network_interfaces'],
            phpFpmPools: $validated['php_fpm_pools'],
            nginxWindow: $validated['nginx_window'],
            prerequisites: $validated['prerequisites'],
            relevantLogLines: $validated['relevant_log_lines'] ?? [],
            canonicalPayload: $validated,
        );
    }
}
