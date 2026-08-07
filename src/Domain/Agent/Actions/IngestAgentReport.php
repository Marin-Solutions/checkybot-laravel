<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Data\AgentReportData;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Data\AgentReportReceipt;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Exceptions\AgentReportForbidden;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Exceptions\AgentReportOperationCollision;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Jobs\EvaluateAgentReport;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\AgentReport;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\RegisteredServer;
use MarinSolutions\CheckybotLaravel\Domain\Security\Foundation\RecursiveRedactor;
use MarinSolutions\CheckybotLaravel\Models\ProjectApiToken;
use Ramsey\Uuid\Uuid;

final readonly class IngestAgentReport
{
    public function __construct(private RecursiveRedactor $redactor) {}

    public function execute(ProjectApiToken $token, AgentReportData $data): AgentReportReceipt
    {
        $server = RegisteredServer::query()
            ->where('server_uuid', $data->serverUuid)
            ->where('project_id', $token->project_id)
            ->where('enabled', true)
            ->first();
        if ($server === null) {
            throw new AgentReportForbidden;
        }

        $sanitizedPayload = $data->canonicalPayload;
        foreach ($sanitizedPayload['relevant_log_lines'] ?? [] as $index => $line) {
            if (is_array($line) && is_string($line['line'] ?? null)) {
                $sanitizedPayload['relevant_log_lines'][$index]['line'] = $this->redactor->redact($line['line']);
            }
        }
        foreach ($sanitizedPayload['prerequisites'] ?? [] as $index => $prerequisite) {
            if (is_array($prerequisite) && is_string($prerequisite['path_hint'] ?? null)) {
                $sanitizedPayload['prerequisites'][$index]['path_hint'] = $this->redactor->redact($prerequisite['path_hint']);
            }
        }
        $canonical = $this->canonicalize($sanitizedPayload);
        $hash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $created = false;

        try {
            DB::transaction(function () use ($server, $data, $canonical, $hash, &$created): void {
                $existing = AgentReport::query()->where('operation_id', $data->operationId)->first();
                if ($existing !== null) {
                    $this->assertSamePayload($existing, $hash);

                    return;
                }

                $report = AgentReport::query()->create([
                    'operation_id' => $data->operationId,
                    'agent_server_id' => $server->getKey(),
                    'project_id' => $server->project_id,
                    'payload_hash' => $hash,
                    'schema_version' => $data->schemaVersion,
                    'agent_version' => $data->agentVersion,
                    'observed_at' => $data->observedAt,
                    'reporting_interval_seconds' => $data->reportingIntervalSeconds,
                    'cpu_five_min_percent' => $data->cpu['five_min_percent'],
                    'memory_used_percent' => $data->memory['used_percent'],
                    'nginx_window_seconds' => $data->nginxWindow['window_seconds'],
                    'nginx_total_requests' => $data->nginxWindow['total_requests'],
                    'nginx_five_xx_count' => $data->nginxWindow['five_xx_count'],
                    'nginx_upstream_timeout_count' => $data->nginxWindow['upstream_timeout_count'],
                    'payload' => $canonical,
                ]);
                $report->disks()->createMany(array_map(static fn (array $sample): array => [
                    'mount' => $sample['mount'],
                    'used_percent' => $sample['used_percent'],
                    'predicted_days_to_full' => $sample['predicted_days_to_full'],
                ], $data->disks));
                $report->networkInterfaces()->createMany(array_map(static fn (array $sample): array => [
                    'interface_name' => $sample['name'],
                    'rx_bytes_total' => $sample['rx_bytes_total'],
                    'tx_bytes_total' => $sample['tx_bytes_total'],
                    'rx_delta_bytes' => $sample['rx_delta_bytes'],
                    'tx_delta_bytes' => $sample['tx_delta_bytes'],
                    'elapsed_seconds' => $sample['elapsed_seconds'],
                    'sample_status' => $sample['sample_status'],
                ], $data->networkInterfaces));
                $report->phpFpmPools()->createMany($data->phpFpmPools);
                $report->prerequisites()->createMany(array_map(fn (array $prerequisite): array => [
                    ...$prerequisite,
                    'path_hint' => $this->redactor->redact($prerequisite['path_hint']),
                ], $data->prerequisites));
                $report->relevantLogLines()->createMany(array_map(fn (array $line): array => [
                    'source' => $line['source'],
                    'observed_at' => $line['observed_at'],
                    'redacted_line' => $this->redactor->redact($line['line']),
                ], $data->relevantLogLines));

                EvaluateAgentReport::dispatch($data->operationId)->afterCommit();
                $created = true;
            }, 3);
        } catch (QueryException $exception) {
            $existing = AgentReport::query()->where('operation_id', $data->operationId)->first();
            if ($existing === null) {
                throw $exception;
            }
            $this->assertSamePayload($existing, $hash);
        }

        return new AgentReportReceipt(
            operationId: $data->operationId,
            serverUuid: $data->serverUuid,
            status: $created ? 'queued' : 'duplicate',
            created: $created,
            evaluationOperationIds: [Uuid::uuid5($data->operationId, 'server-aggregate-evaluation')->toString()],
        );
    }

    private function assertSamePayload(AgentReport $existing, string $hash): void
    {
        if (! hash_equals($existing->payload_hash, $hash)) {
            throw new AgentReportOperationCollision;
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }

        return $value;
    }
}
