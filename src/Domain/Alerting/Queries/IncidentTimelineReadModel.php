<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Queries;

use Illuminate\Auth\Access\AuthorizationException;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Queries\LatestRootCause;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\AuthorizedMonitorIdentity;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\IncidentGroup;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\IncidentGroupMember;
use MarinSolutions\CheckybotLaravel\Models\MonitorState;
use MarinSolutions\CheckybotLaravel\Models\MonitorTransition;

final class IncidentTimelineReadModel
{
    public function __construct(private readonly LatestRootCause $rootCauses) {}

    /**
     * @return array{
     *   current_state: string,
     *   entered_at: string,
     *   transitions: list<array<string, mixed>>,
     *   incident_groups: list<array<string, mixed>>,
     *   annotation_slots: list<array{key: string, value: string|null}>
     * }|null
     */
    public function forMonitor(AuthorizedMonitorIdentity $authorized): ?array
    {
        $identity = $authorized->identity;
        if (! hash_equals($authorized->authorizedProjectId, $identity->projectId)) {
            throw new AuthorizationException('The authorization context cannot read this monitor timeline.');
        }

        $where = [
            'project_id' => $identity->projectId,
            'monitor_id' => $identity->monitorId,
            'monitor_type' => $identity->type->value,
        ];
        $state = MonitorState::query()->where($where)->first();
        if ($state === null) {
            return null;
        }

        $transitions = MonitorTransition::query()
            ->where($where)
            ->orderBy('occurred_at')
            ->orderBy('operation_sequence')
            ->get();

        $timelineTransitions = $transitions->values()->map(function (MonitorTransition $transition, int $index) use ($transitions): array {
            /** @var MonitorTransition|null $next */
            $next = $transitions->get($index + 1);

            return [
                'transition_id' => $transition->public_id,
                'from' => $transition->from_state->value,
                'to' => $transition->to_state->value,
                'severity' => $transition->severity->value,
                'occurred_at' => $transition->occurred_at->toRfc3339String(),
                'duration_seconds' => $next === null
                    ? null
                    : max(0, (int) $transition->occurred_at->diffInSeconds($next->occurred_at, false)),
                'reason_code' => $transition->reason_code,
                'group_id' => $transition->incident_group_id,
                'maintenance_suppressed' => (bool) $transition->maintenance_suppressed,
            ];
        })->all();

        $groupIds = IncidentGroupMember::query()
            ->where($where)
            ->orderBy('confirmed_down_at')
            ->pluck('group_id')
            ->unique()
            ->values();
        $groups = IncidentGroup::query()
            ->where('project_id', $identity->projectId)
            ->whereIn('public_id', $groupIds)
            ->orderBy('opened_at')
            ->get()
            ->map(function (IncidentGroup $group): array {
                $affected = IncidentGroupMember::query()
                    ->where('group_id', $group->public_id)
                    ->orderBy('confirmed_down_at')
                    ->orderBy('id')
                    ->get()
                    ->map(static fn (IncidentGroupMember $member): array => [
                        'project_uuid' => $member->project_id,
                        'monitor_uuid' => $member->monitor_id,
                        'type' => $member->monitor_type->value,
                    ])->all();

                return [
                    'group_id' => $group->public_id,
                    'opened_at' => $group->opened_at->toRfc3339String(),
                    'closed_at' => $group->closed_at?->toRfc3339String(),
                    'notification_thread_key' => $group->notification_thread_key,
                    'affected_monitors' => $affected,
                ];
            })->all();

        return [
            'current_state' => $state->state->value,
            'entered_at' => ($state->entered_at ?? $state->observed_at)->toRfc3339String(),
            'transitions' => $timelineTransitions,
            'incident_groups' => $groups,
            'annotation_slots' => [
                ['key' => 'summary', 'value' => null],
                ['key' => 'root_cause', 'value' => $this->rootCauses->forMonitor($identity)],
                ['key' => 'customer_impact', 'value' => null],
                ['key' => 'remediation', 'value' => null],
            ],
        ];
    }
}
