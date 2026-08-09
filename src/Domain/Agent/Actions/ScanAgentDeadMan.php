<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\AgentMonitorEvaluation;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\AgentServerLiveness;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\MonitorResultIngestionInterface;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\NormalizedMonitorResult;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\NormalizedThresholds;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorIdentity;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorType;
use Ramsey\Uuid\Uuid;
use Throwable;

final readonly class ScanAgentDeadMan
{
    public function __construct(private MonitorResultIngestionInterface $ingestion) {}

    public function execute(?CarbonImmutable $observedAt = null): int
    {
        $scanAt = ($observedAt ?? CarbonImmutable::now('UTC'))->utc();
        $submitted = 0;
        $livenessIds = AgentServerLiveness::query()
            ->whereHas('server', static fn ($query) => $query->where('enabled', true))
            ->orderBy('id')->pluck('id');

        foreach ($livenessIds as $livenessId) {
            $evaluation = $this->evaluationFor((int) $livenessId, $scanAt);
            if ($evaluation === null) {
                continue;
            }

            $evaluation->loadMissing('server');
            $this->ingestion->ingest(new NormalizedMonitorResult(
                operationId: $evaluation->operation_id,
                identity: new MonitorIdentity(
                    $evaluation->project_id,
                    $evaluation->server->server_uuid,
                    MonitorType::Server,
                ),
                source: 'push',
                observedAt: $evaluation->observed_at,
                signal: $evaluation->signal,
                reasonCode: $evaluation->reason_code,
                value: (float) $evaluation->band_value,
                thresholds: new NormalizedThresholds(1.0, 2.0, 1.0),
            ));
            $submitted++;
        }

        return $submitted;
    }

    private function evaluationFor(int $livenessId, CarbonImmutable $scanAt): ?AgentMonitorEvaluation
    {
        return $this->serializedTransaction(function () use ($livenessId, $scanAt): ?AgentMonitorEvaluation {
            $liveness = AgentServerLiveness::query()->with('server')->whereKey($livenessId)->lockForUpdate()->first();
            if ($liveness === null || ! $liveness->server->enabled) {
                return null;
            }

            $elapsed = $liveness->last_accepted_observed_at->diffInSeconds($scanAt, false);
            if ($elapsed < 180) {
                return null;
            }

            $signal = $elapsed >= 300 ? 'critical' : 'warn';
            $band = $signal === 'critical' ? 2 : 1;
            $reason = $signal === 'critical' ? 'agent_dead_man_critical' : 'agent_dead_man_warn';
            $operationId = Uuid::uuid5(
                $liveness->server->server_uuid,
                implode('|', [
                    'agent-dead-man',
                    $liveness->last_accepted_observed_at->toRfc3339String(),
                    $scanAt->format('Y-m-d\TH:i:s.u\Z'),
                    $signal,
                ]),
            )->toString();

            return AgentMonitorEvaluation::query()->firstOrCreate(
                ['operation_id' => $operationId],
                [
                    'agent_server_id' => $liveness->agent_server_id,
                    'agent_report_id' => null,
                    'project_id' => $liveness->project_id,
                    'observation_kind' => 'dead_man',
                    'signal' => $signal,
                    'band_value' => $band,
                    'reason_code' => $reason,
                    'details' => [
                        'elapsed_seconds' => $elapsed,
                        'reporting_interval_seconds' => (int) $liveness->reporting_interval_seconds,
                        'last_accepted_observed_at' => $liveness->last_accepted_observed_at->toRfc3339String(),
                    ],
                    'observed_at' => $scanAt,
                ],
            );
        });
    }

    private function serializedTransaction(\Closure $callback): mixed
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return DB::transaction($callback, 5);
        }

        DB::statement('BEGIN IMMEDIATE');
        try {
            $result = $callback();
            DB::statement('COMMIT');

            return $result;
        } catch (Throwable $exception) {
            if (DB::connection()->getPdo()->inTransaction()) {
                DB::statement('ROLLBACK');
            }
            throw $exception;
        }
    }
}
