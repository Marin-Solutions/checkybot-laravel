<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\PullRecheckProducer;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Support\DeterministicPullRecheckProducer;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Actions\RefreshDomainExpiry;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Models\ExpandedWebsiteMonitor;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorIdentity;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorType;
use Ramsey\Uuid\Uuid;

final readonly class DomainExpiryPullRecheckProducer implements PullRecheckProducer
{
    public function __construct(
        private RefreshDomainExpiry $refresh,
        private DeterministicPullRecheckProducer $fallback,
    ) {}

    public function request(MonitorIdentity $identity, int $attemptNumber, string $requestId): void
    {
        if ($identity->type !== MonitorType::Website || ! Schema::hasTable('expanded_website_monitors')) {
            $this->fallback->request($identity, $attemptNumber, $requestId);

            return;
        }
        $monitor = ExpandedWebsiteMonitor::query()
            ->where('project_id', $identity->projectId)
            ->where('monitor_id', $identity->monitorId)
            ->where('check_type', 'domain_expiry')
            ->where('enabled', true)
            ->first();
        if ($monitor === null) {
            $this->fallback->request($identity, $attemptNumber, $requestId);

            return;
        }

        $operationId = Uuid::uuid5($identity->monitorId, "domain-refresh-retry|{$attemptNumber}|{$requestId}")->toString();
        $this->refresh->execute($monitor, CarbonImmutable::now('UTC'), $operationId);
    }
}
