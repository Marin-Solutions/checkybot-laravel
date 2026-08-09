<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Jobs\EmitIncidentIntent;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\IncidentGroup;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\IncidentGroupMember;
use MarinSolutions\CheckybotLaravel\Models\MonitorTransition;

final readonly class GroupIncidentTransition
{
    private const COALESCING_SECONDS = 300;

    private const COLLECTION_SECONDS = 30;

    public function __construct(private WriteNotificationIntent $intents) {}

    public function execute(string $transitionId): void
    {
        DB::transaction(function () use ($transitionId): void {
            $transition = MonitorTransition::query()->where('public_id', $transitionId)->lockForUpdate()->first();
            if ($transition === null || (bool) $transition->maintenance_suppressed) {
                return;
            }

            if (! in_array($transition->to_state->value, ['down', 'healthy'], true)) {
                return;
            }

            $this->lockProject($transition->project_id);
            if ($transition->to_state->value === 'down') {
                $this->confirmedDown($transition);

                return;
            }

            $this->confirmedHealthy($transition);
        }, 5);
    }

    private function confirmedDown(MonitorTransition $transition): void
    {
        if ($transition->incident_group_id !== null) {
            return;
        }

        $occurredAt = CarbonImmutable::instance($transition->occurred_at);
        $group = IncidentGroup::query()
            ->where('project_id', $transition->project_id)
            ->whereNull('closed_at')
            ->where('latest_member_at', '>=', $occurredAt->subSeconds(self::COALESCING_SECONDS))
            ->where('opened_at', '<=', $occurredAt->addSeconds(self::COALESCING_SECONDS))
            ->orderByDesc('latest_member_at')
            ->lockForUpdate()
            ->first();

        $created = false;
        if ($group === null) {
            $created = true;
            $groupId = (string) Str::uuid();
            $group = IncidentGroup::query()->create([
                'public_id' => $groupId,
                'project_id' => $transition->project_id,
                'severity' => $transition->severity->value,
                'notification_thread_key' => "incident:{$transition->project_id}:{$groupId}",
                'opened_at' => $occurredAt,
                'latest_member_at' => $occurredAt,
                'collection_due_at' => $occurredAt->addSeconds(self::COLLECTION_SECONDS),
            ]);
        }

        $member = IncidentGroupMember::query()->firstOrNew([
            'group_id' => $group->public_id,
            'monitor_type' => $transition->monitor_type->value,
            'monitor_id' => $transition->monitor_id,
        ]);
        if (! $member->exists) {
            $member->forceFill([
                'project_id' => $transition->project_id,
                'severity' => $transition->severity->value,
                'down_transition_id' => $transition->public_id,
                'confirmed_down_at' => $occurredAt,
            ]);
        } else {
            $member->setAttribute('severity', $this->maximumSeverity($member->severity->value, $transition->severity->value));
            $member->confirmed_healthy_at = null;
            $member->healthy_transition_id = null;
        }
        $member->save();

        $openedAt = $occurredAt->lessThan($group->opened_at) ? $occurredAt : $group->opened_at;
        $latestAt = $occurredAt->greaterThan($group->latest_member_at) ? $occurredAt : $group->latest_member_at;
        $group->opened_at = $openedAt;
        $group->latest_member_at = $latestAt;
        $group->collection_due_at = $openedAt->addSeconds(self::COLLECTION_SECONDS);
        $group->setAttribute('severity', $this->maximumSeverity($group->severity->value, $transition->severity->value));
        $group->save();

        DB::table('monitor_transitions')->where('id', $transition->getKey())->update([
            'incident_group_id' => $group->public_id,
            'updated_at' => now(),
        ]);

        if ($group->incident_emitted_at !== null) {
            $this->intents->incident($group->fresh());
        }

        if ($created) {
            EmitIncidentIntent::dispatch($group->public_id)->delay($group->collection_due_at)->afterCommit();
        }
    }

    private function confirmedHealthy(MonitorTransition $transition): void
    {
        $members = IncidentGroupMember::query()
            ->where('project_id', $transition->project_id)
            ->where('monitor_id', $transition->monitor_id)
            ->where('monitor_type', $transition->monitor_type->value)
            ->whereNull('confirmed_healthy_at')
            ->whereIn('group_id', IncidentGroup::query()->whereNull('closed_at')->select('public_id'))
            ->lockForUpdate()
            ->get();
        $linkedGroupId = null;

        foreach ($members as $member) {
            $group = IncidentGroup::query()->where('public_id', $member->group_id)->lockForUpdate()->first();
            if ($group === null || $group->closed_at !== null) {
                continue;
            }

            $member->confirmed_healthy_at = $transition->occurred_at;
            $member->healthy_transition_id = $transition->public_id;
            $member->save();
            $linkedGroupId ??= $group->public_id;

            $remaining = IncidentGroupMember::query()
                ->where('group_id', $group->public_id)
                ->whereNull('confirmed_healthy_at')
                ->exists();
            if ($remaining) {
                continue;
            }

            $closedAt = CarbonImmutable::instance($transition->occurred_at);
            $group->closed_at = $closedAt;
            $group->save();
            if ($group->incident_emitted_at !== null) {
                $this->intents->recovery($group, $closedAt);
            }
        }

        if ($linkedGroupId !== null) {
            DB::table('monitor_transitions')->where('id', $transition->getKey())->update([
                'incident_group_id' => $linkedGroupId,
                'updated_at' => now(),
            ]);
        }
    }

    private function lockProject(string $projectId): void
    {
        DB::table('alerting_incident_project_locks')->insertOrIgnore([
            'project_id' => $projectId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('alerting_incident_project_locks')
            ->where('project_id', $projectId)
            ->lockForUpdate()
            ->first();
    }

    private function maximumSeverity(string $left, string $right): string
    {
        return $left === 'critical' || $right === 'critical' ? 'critical' : 'warn';
    }
}
