<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Queries;

use Carbon\CarbonImmutable;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\LifecycleState;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorType;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\StatusSummary;
use MarinSolutions\CheckybotLaravel\Models\MonitorState;

final class StatusSummaryQuery
{
    public function forProject(string $projectId): StatusSummary
    {
        $empty = ['healthy' => 0, 'warn' => 0, 'down' => 0];
        $counts = ['servers' => $empty, 'websites' => $empty, 'apis' => $empty];

        $states = MonitorState::query()
            ->forProject($projectId)
            ->get(['monitor_type', 'state', 'observed_at']);

        /** @var MonitorState $state */
        foreach ($states as $state) {
            $type = match ($state->monitor_type) {
                MonitorType::Server => 'servers',
                MonitorType::Website => 'websites',
                MonitorType::Api => 'apis',
            };
            $cell = $state->state === LifecycleState::Recovering
                ? LifecycleState::Warn->value
                : $state->state->value;
            $counts[$type][$cell]++;
        }

        /** @var CarbonImmutable|null $updatedAt */
        $updatedAt = $states->max('observed_at');
        $staleAfter = max(0, (int) config('checkybot.monitor_foundation.status_summary.stale_after_seconds', 900));

        return new StatusSummary(
            counts: $counts,
            updatedAt: $updatedAt,
            stale: $updatedAt === null || $updatedAt->lt(CarbonImmutable::now()->subSeconds($staleAfter)),
        );
    }
}
