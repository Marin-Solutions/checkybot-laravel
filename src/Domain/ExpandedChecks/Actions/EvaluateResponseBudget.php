<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Actions;

use Carbon\CarbonImmutable;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\MonitorResultIngestionInterface;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\NormalizedMonitorResult;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\NormalizedThresholds;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Contracts\StoredCheckSpeedReader;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Models\ExpandedWebsiteMonitor;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Support\NearestRankPercentile;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Support\ReservesExpandedEvaluation;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorIdentity;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorType;
use Ramsey\Uuid\Uuid;

final readonly class EvaluateResponseBudget
{
    use ReservesExpandedEvaluation;

    public function __construct(
        private StoredCheckSpeedReader $reader,
        private NearestRankPercentile $percentile,
        private MonitorResultIngestionInterface $ingestion,
    ) {}

    public function execute(ExpandedWebsiteMonitor $monitor, ?CarbonImmutable $observedAt = null): bool
    {
        if (! $monitor->enabled || $monitor->check_type !== 'response_budget') {
            return false;
        }
        $observedAt = ($observedAt ?? CarbonImmutable::now('UTC'))->utc()->startOfMinute();
        $operationId = Uuid::uuid5($monitor->monitor_id, 'response-budget|'.$observedAt->format('Y-m-d\TH:i:00\Z'))->toString();
        [$evaluation, $created] = $this->reserve($operationId, [
            'expanded_website_monitor_id' => $monitor->getKey(),
            'project_id' => $monitor->project_id,
            'kind' => 'response_budget',
            'status' => 'pending',
            'observed_at' => $observedAt,
        ]);
        if (! $created) {
            return false;
        }

        $samples = $this->reader->successfulFiniteFor(
            $monitor->project_id,
            $monitor->monitor_id,
            $observedAt->subSeconds((int) $monitor->sample_max_age_seconds),
            (int) $monitor->retained_sample_limit,
        );
        if ($samples === []) {
            $evaluation->forceFill([
                'status' => 'unavailable',
                'reason_code' => 'response_budget_unavailable',
                'details' => ['healthy_reported' => false, 'sample_count' => 0],
            ])->save();

            return false;
        }

        $values = array_map(static fn ($sample): float => $sample->milliseconds, $samples);
        $p95 = $this->percentile->calculate($values, 0.95);
        $signal = $p95 > 2000.0 ? 'warn' : 'healthy';
        $band = $signal === 'warn' ? 1.0 : 0.0;
        $reason = $signal === 'warn' ? 'response_budget_p95_warn' : 'response_budget_p95_healthy';

        $this->ingestion->ingest(new NormalizedMonitorResult(
            operationId: $operationId,
            identity: new MonitorIdentity($monitor->project_id, $monitor->monitor_id, MonitorType::Website),
            source: 'push',
            observedAt: $observedAt,
            signal: $signal,
            reasonCode: $reason,
            value: $band,
            thresholds: new NormalizedThresholds(1.0, 2.0, 1.0),
        ));
        $evaluation->forceFill([
            'status' => 'submitted',
            'signal' => $signal,
            'reason_code' => $reason,
            'value' => $band,
            'details' => [
                'p95_ms' => $p95,
                'sample_count' => count($samples),
                'first_observed_at' => $samples[0]->observedAt->toRfc3339String(),
                'last_observed_at' => $samples[array_key_last($samples)]->observedAt->toRfc3339String(),
            ],
        ])->save();

        return true;
    }
}
