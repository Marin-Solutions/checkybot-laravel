<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Actions;

use Carbon\CarbonImmutable;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\MonitorResultIngestionInterface;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\NormalizedMonitorResult;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Contracts\DomainExpiryLookup;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Data\DomainName;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Models\DomainExpiryObservation;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Models\ExpandedWebsiteMonitor;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Support\ReservesExpandedEvaluation;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorIdentity;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorType;
use Ramsey\Uuid\Uuid;

final readonly class RefreshDomainExpiry
{
    use ReservesExpandedEvaluation;

    public function __construct(
        private DomainExpiryLookup $lookup,
        private MonitorResultIngestionInterface $ingestion,
    ) {}

    public function execute(ExpandedWebsiteMonitor $monitor, ?CarbonImmutable $observedAt = null, ?string $operationId = null): bool
    {
        if (! $monitor->enabled || $monitor->check_type !== 'domain_expiry' || $monitor->canonical_domain === null) {
            return false;
        }
        $observedAt = ($observedAt ?? CarbonImmutable::now('UTC'))->utc();
        $operationId ??= Uuid::uuid5($monitor->monitor_id, 'domain-refresh|'.$observedAt->format('Y-m-d'))->toString();
        [$evaluation, $created] = $this->reserve($operationId, [
            'expanded_website_monitor_id' => $monitor->getKey(),
            'project_id' => $monitor->project_id,
            'kind' => 'domain_refresh',
            'status' => 'pending',
            'observed_at' => $observedAt,
        ]);
        if (! $created) {
            return false;
        }

        $result = $this->lookup->lookup(new DomainName($monitor->canonical_domain));
        if ($result->successful) {
            DomainExpiryObservation::query()->updateOrCreate(
                ['expanded_website_monitor_id' => $monitor->getKey()],
                [
                    'canonical_domain' => $result->domain->ascii,
                    'expires_at' => $result->expiresAt,
                    'source' => $result->source,
                    'fetched_at' => $result->fetchedAt,
                ],
            );
        }

        $signal = $result->successful ? 'success' : 'failure';
        $reason = $result->successful ? 'domain_lookup_success' : $result->failureCode;
        $this->ingestion->ingest(new NormalizedMonitorResult(
            operationId: $operationId,
            identity: new MonitorIdentity($monitor->project_id, $monitor->monitor_id, MonitorType::Website),
            source: 'pull',
            observedAt: $observedAt,
            signal: $signal,
            reasonCode: $reason,
        ));
        $evaluation->forceFill([
            'status' => 'submitted',
            'signal' => $signal,
            'reason_code' => $reason,
            'details' => [
                'domain' => $result->domain->ascii,
                'source' => $result->source,
                'retryable' => $result->retryable,
            ],
        ])->save();

        return true;
    }
}
