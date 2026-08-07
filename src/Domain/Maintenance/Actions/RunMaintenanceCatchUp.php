<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Maintenance\Actions;

use Closure;
use Illuminate\Support\Facades\DB;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Actions\WriteNotificationIntent;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\IncidentGroup;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\IncidentGroupMember;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Models\MaintenanceMode;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Support\MaintenanceSilencer;
use MarinSolutions\CheckybotLaravel\Models\MonitorState;
use MarinSolutions\CheckybotLaravel\Models\MonitorTransition;
use Ramsey\Uuid\Uuid;
use Throwable;

final readonly class RunMaintenanceCatchUp
{
    public function __construct(
        private WriteNotificationIntent $intents,
        private MaintenanceSilencer $silencer,
    ) {}

    public function execute(string $maintenanceModeId): void
    {
        $this->serializedTransaction(function () use ($maintenanceModeId): void {
            $mode = MaintenanceMode::query()->where('public_id', $maintenanceModeId)->lockForUpdate()->first();
            if ($mode === null || $mode->catch_up_claimed_at !== null) {
                return;
            }
            if ($mode->cleared_at === null && $mode->ends_at->isFuture()) {
                return;
            }

            $mode->forceFill(['catch_up_claimed_at' => now()])->save();
            $projects = $mode->scope === 'project'
                ? collect([$mode->project_id])
                : MonitorState::query()->where('state', '!=', 'healthy')->distinct()->pluck('project_id');

            foreach ($projects->filter()->unique() as $projectId) {
                $this->catchUpProject($mode, (string) $projectId);
            }
        });
    }

    private function catchUpProject(MaintenanceMode $mode, string $projectId): void
    {
        if ($this->silencer->isSilencedNow($projectId)) {
            return;
        }

        $states = MonitorState::query()
            ->where('project_id', $projectId)
            ->where('state', '!=', 'healthy')
            ->orderBy('entered_at')
            ->orderBy('id')
            ->get();
        if ($states->isEmpty()) {
            return;
        }

        $groupId = Uuid::uuid5(Uuid::NAMESPACE_URL, "checkybot:maintenance-catch-up:{$mode->public_id}:{$projectId}")->toString();
        $openedAt = $states->min(static fn (MonitorState $state) => $state->entered_at ?? $state->observed_at);
        $severity = $states->contains(static fn (MonitorState $state): bool => $state->severity->value === 'critical')
            ? 'critical'
            : 'warn';
        $group = IncidentGroup::query()->firstOrCreate(['public_id' => $groupId], [
            'project_id' => $projectId,
            'severity' => $severity,
            'notification_thread_key' => "maintenance:{$mode->public_id}:{$projectId}",
            'opened_at' => $openedAt,
            'latest_member_at' => now(),
            'collection_due_at' => now(),
            'collection_dispatched_at' => now(),
            'incident_emitted_at' => now(),
        ]);

        foreach ($states as $state) {
            $transitionId = MonitorTransition::query()
                ->where('project_id', $projectId)
                ->where('monitor_id', $state->monitor_id)
                ->where('monitor_type', $state->monitor_type->value)
                ->orderByDesc('occurred_at')
                ->orderByDesc('operation_sequence')
                ->value('public_id');
            $transitionId ??= Uuid::uuid5(Uuid::NAMESPACE_URL, "checkybot:maintenance-state:{$projectId}:{$state->monitor_type->value}:{$state->monitor_id}")->toString();

            IncidentGroupMember::query()->firstOrCreate([
                'group_id' => $groupId,
                'monitor_type' => $state->monitor_type->value,
                'monitor_id' => $state->monitor_id,
            ], [
                'project_id' => $projectId,
                'severity' => $state->severity->value,
                'down_transition_id' => $transitionId,
                'confirmed_down_at' => $state->entered_at ?? $state->observed_at,
            ]);
        }

        $this->intents->incident($group->fresh());
    }

    private function serializedTransaction(Closure $callback): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::transaction($callback, 5);

            return;
        }

        DB::statement('BEGIN IMMEDIATE');
        try {
            $callback();
            DB::statement('COMMIT');
        } catch (Throwable $exception) {
            if (DB::connection()->getPdo()->inTransaction()) {
                DB::statement('ROLLBACK');
            }
            throw $exception;
        }
    }
}
