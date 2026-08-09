<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Actions;

use Carbon\CarbonImmutable;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\MonitorResultIngestionInterface;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\NormalizedMonitorResult;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\NormalizedThresholds;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Models\ExpandedWebsiteMonitor;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Support\ReservesExpandedEvaluation;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorIdentity;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorType;
use Ramsey\Uuid\Uuid;

final readonly class EvaluateDomainExpiry
{
    use ReservesExpandedEvaluation;

    private const MAX_OBSERVATION_AGE_SECONDS = 129600;

    public function __construct(private MonitorResultIngestionInterface $ingestion) {}

    public function execute(ExpandedWebsiteMonitor $monitor, ?CarbonImmutable $observedAt = null): bool
    {
        if (! $monitor->enabled || $monitor->check_type !== 'domain_expiry') {
            return false;
        }
        $observedAt = ($observedAt ?? CarbonImmutable::now('UTC'))->utc()->startOfMinute();
        $operationId = Uuid::uuid5($monitor->monitor_id, 'domain-budget|'.$observedAt->format('Y-m-d\TH:i:00\Z'))->toString();
        [$evaluation, $created] = $this->reserve($operationId, [
            'expanded_website_monitor_id' => $monitor->getKey(),
            'project_id' => $monitor->project_id,
            'kind' => 'domain_budget',
            'status' => 'pending',
            'observed_at' => $observedAt,
        ]);
        if (! $created) {
            return false;
        }

        $observation = $monitor->domainObservation()->first();
        if ($observation === null || $observation->fetched_at->lt($observedAt->subSeconds(self::MAX_OBSERVATION_AGE_SECONDS))) {
            $evaluation->forceFill([
                'status' => 'unavailable',
                'reason_code' => $observation === null ? 'domain_lookup_absent' : 'domain_lookup_stale',
                'details' => ['healthy_reported' => false],
            ])->save();

            return false;
        }

        if ($observation->expires_at->lt($observedAt)) {
            [$signal, $band, $reason, $wholeDays] = ['critical', 2.0, 'domain_expired', -1];
        } else {
            $wholeDays = intdiv((int) floor($observedAt->diffInSeconds($observation->expires_at, false)), 86400);
            [$signal, $band, $reason] = $wholeDays <= 30
                ? ['warn', 1.0, 'domain_expiry_warn']
                : ['healthy', 0.0, 'domain_expiry_healthy'];
        }

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
                'whole_days_remaining' => $wholeDays,
                'expires_at' => $observation->expires_at->toRfc3339String(),
                'lookup_fetched_at' => $observation->fetched_at->toRfc3339String(),
            ],
        ])->save();

        return true;
    }
}
