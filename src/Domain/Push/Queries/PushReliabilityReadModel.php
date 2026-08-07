<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Push\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use MarinSolutions\CheckybotLaravel\Domain\Push\Contracts\ProjectIdentity;

final class PushReliabilityReadModel
{
    /** @return array<string, int|string|bool|null> */
    public function forProject(ProjectIdentity $project): array
    {
        // The current UTC day is still open and can receive another critical
        // intent, so only elapsed calendar days are eligible for proving.
        $end = CarbonImmutable::instance(now())->utc()->startOfDay()->subDay();
        $start = $end->subDays(27);
        $rows = DB::table('push_proving_days')
            ->where('project_id', $project->uuid)
            ->whereBetween('proving_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('proving_date')
            ->get();
        $byDate = $rows->keyBy('proving_date');
        $consecutive = 0;
        for ($date = $end; $date->greaterThanOrEqualTo($start); $date = $date->subDay()) {
            $row = $byDate->get($date->toDateString());
            if ($row === null || (int) $row->critical_intents < 1
                || (int) $row->failed_or_missing_pairs !== 0
                || (int) $row->expo_accepted !== (int) $row->critical_intents
                || (int) $row->legacy_webhook_accepted !== (int) $row->critical_intents) {
                break;
            }
            $consecutive++;
        }
        $started = DB::table('push_proving_days')->where('project_id', $project->uuid)->min('proving_date');
        $lastFailure = DB::table('push_proving_days')->where('project_id', $project->uuid)->max('last_failure_at');

        return [
            'proving_started_at' => $started === null ? null : CarbonImmutable::parse($started, 'UTC')->startOfDay()->toRfc3339String(),
            'window_ends_at' => $rows->isEmpty() ? null : $end->endOfDay()->toRfc3339String(),
            'critical_intents' => (int) $rows->sum('critical_intents'),
            'expo_accepted' => (int) $rows->sum('expo_accepted'),
            'legacy_webhook_accepted' => (int) $rows->sum('legacy_webhook_accepted'),
            'failed_or_missing_pairs' => (int) $rows->sum('failed_or_missing_pairs'),
            'consecutive_complete_days' => $consecutive,
            'retirement_ready' => $consecutive === 28,
            'last_failure_at' => $lastFailure === null ? null : CarbonImmutable::parse($lastFailure)->utc()->toRfc3339String(),
        ];
    }
}
