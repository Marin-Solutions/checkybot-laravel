<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\IngestionReceipt;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\MonitorResultIngestionInterface;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\NormalizedMonitorResult;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Jobs\ProcessMonitorResult;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\AlertingResult;

final readonly class IngestMonitorResult implements MonitorResultIngestionInterface
{
    public function ingest(NormalizedMonitorResult $result): IngestionReceipt
    {
        $payload = $result->toArray();
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $created = false;

        try {
            DB::transaction(function () use ($result, $hash, &$created): void {
                $existing = AlertingResult::query()->where('operation_id', $result->operationId)->first();
                if ($existing !== null) {
                    $this->assertSamePayload($existing, $hash);

                    return;
                }

                AlertingResult::query()->create([
                    'operation_id' => $result->operationId,
                    'payload_hash' => $hash,
                    'project_id' => $result->identity->projectId,
                    'monitor_id' => $result->identity->monitorId,
                    'monitor_type' => $result->identity->type->value,
                    'source' => $result->source,
                    'signal' => $result->signal,
                    'observed_at' => $result->observedAt,
                    'reason_code' => $result->reasonCode,
                    'value' => $result->value,
                    'thresholds' => $result->thresholds?->toArray(),
                    'status' => 'queued',
                ]);
                $created = true;
            }, 3);
        } catch (QueryException $exception) {
            $existing = AlertingResult::query()->where('operation_id', $result->operationId)->first();
            if ($existing === null) {
                throw $exception;
            }
            $this->assertSamePayload($existing, $hash);
        }

        if ($created) {
            ProcessMonitorResult::dispatch($result->operationId);
        }

        return new IngestionReceipt($created, $result->operationId, $created ? 'queued' : 'duplicate');
    }

    private function assertSamePayload(AlertingResult $existing, string $hash): void
    {
        if (! hash_equals($existing->payload_hash, $hash)) {
            throw ValidationException::withMessages([
                'operation_id' => ['The operation id is already associated with a different immutable result.'],
            ]);
        }
    }
}
