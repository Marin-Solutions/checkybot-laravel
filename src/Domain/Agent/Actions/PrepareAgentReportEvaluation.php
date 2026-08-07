<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\AgentMonitorEvaluation;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\AgentReport;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\MonitorResultIngestionInterface;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\NormalizedMonitorResult;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\NormalizedThresholds;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorIdentity;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorType;
use Ramsey\Uuid\Uuid;

final readonly class PrepareAgentReportEvaluation
{
    public function __construct(
        private EvaluateServerMetrics $evaluator,
        private MonitorResultIngestionInterface $ingestion,
    ) {}

    public function execute(string $operationId): void
    {
        $report = AgentReport::query()->where('operation_id', $operationId)->firstOrFail();
        $evaluation = AgentMonitorEvaluation::query()->where('agent_report_id', $report->getKey())->first();

        if ($evaluation === null) {
            try {
                $evaluation = DB::transaction(function () use ($report): AgentMonitorEvaluation {
                    $locked = AgentReport::query()
                        ->with(['server', 'disks', 'networkInterfaces', 'phpFpmPools', 'prerequisites'])
                        ->whereKey($report->getKey())->lockForUpdate()->firstOrFail();
                    $existing = AgentMonitorEvaluation::query()->where('agent_report_id', $locked->getKey())->first();
                    if ($existing !== null) {
                        return $existing;
                    }

                    $linkCap = (int) $locked->server->link_cap_bps;
                    $result = $this->evaluator->execute($locked, $linkCap);
                    $locked->forceFill([
                        'evaluation_link_cap_bps' => $linkCap,
                        'evaluation_prepared_at' => now(),
                    ])->save();

                    return AgentMonitorEvaluation::query()->create([
                        'operation_id' => Uuid::uuid5($locked->operation_id, 'server-aggregate-evaluation')->toString(),
                        'agent_server_id' => $locked->agent_server_id,
                        'agent_report_id' => $locked->getKey(),
                        'project_id' => $locked->project_id,
                        'observation_kind' => 'report',
                        'signal' => $result->signal,
                        'band_value' => $result->bandValue,
                        'reason_code' => $result->reasonCode,
                        'details' => $result->details,
                        'observed_at' => $locked->observed_at,
                    ]);
                }, 3);
            } catch (QueryException $exception) {
                $evaluation = AgentMonitorEvaluation::query()->where('agent_report_id', $report->getKey())->first();
                if ($evaluation === null) {
                    throw $exception;
                }
            }
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
    }
}
