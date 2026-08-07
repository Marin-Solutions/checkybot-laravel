<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Actions;

use Carbon\CarbonImmutable;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\IncidentGroup;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\IncidentGroupMember;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\NotificationIntent;
use MarinSolutions\CheckybotLaravel\Models\OutboxEvent;
use Ramsey\Uuid\Uuid;

final class WriteNotificationIntent
{
    public const CONTRACT_VERSION = 'notification-intent.v1';

    public function incident(IncidentGroup $group): NotificationIntent
    {
        return $this->write($group, 'incident', null);
    }

    public function recovery(IncidentGroup $group, CarbonImmutable $closedAt): NotificationIntent
    {
        $downtime = max(0, (int) $group->opened_at->diffInSeconds($closedAt, false));

        return $this->write($group, 'recovery', $downtime);
    }

    private function write(IncidentGroup $group, string $phase, ?int $downtimeSeconds): NotificationIntent
    {
        $intentId = Uuid::uuid5(Uuid::NAMESPACE_URL, "checkybot:notification-intent:{$group->public_id}:{$phase}")->toString();
        $operationId = Uuid::uuid5(Uuid::NAMESPACE_URL, "checkybot:notification-operation:{$group->public_id}:{$phase}")->toString();
        $existing = NotificationIntent::query()
            ->where('group_id', $group->public_id)
            ->where('phase', $phase)
            ->first();
        $emittedAt = $existing === null ? CarbonImmutable::instance(now()) : $existing->emitted_at;
        $payload = $this->payload($group, $intentId, $operationId, $phase, $emittedAt, $downtimeSeconds);

        $intent = NotificationIntent::query()->updateOrCreate(
            ['group_id' => $group->public_id, 'phase' => $phase],
            [
                'public_id' => $intentId,
                'operation_id' => $operationId,
                'project_id' => $group->project_id,
                'payload' => $payload,
                'emitted_at' => $emittedAt,
            ],
        );

        OutboxEvent::query()->updateOrCreate(
            ['operation_id' => $operationId],
            [
                'event_type' => 'notification.intent.created',
                'contract_version' => self::CONTRACT_VERSION,
                'payload' => $payload,
                'status' => 'pending',
                'available_at' => now(),
            ],
        );

        return $intent;
    }

    /** @return array<string, mixed> */
    private function payload(
        IncidentGroup $group,
        string $intentId,
        string $operationId,
        string $phase,
        CarbonImmutable $emittedAt,
        ?int $downtimeSeconds,
    ): array {
        $members = IncidentGroupMember::query()
            ->where('group_id', $group->public_id)
            ->orderBy('confirmed_down_at')
            ->orderBy('id')
            ->get();
        $affected = $members->map(static fn (IncidentGroupMember $member): array => [
            'project_uuid' => $member->project_id,
            'monitor_uuid' => $member->monitor_id,
            'type' => $member->monitor_type->value,
        ])->all();
        $monitorIds = $members->pluck('monitor_id')->unique()->values()->all();

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'operation_id' => $operationId,
            'intent_id' => $intentId,
            'phase' => $phase,
            'project_uuid' => $group->project_id,
            'group_id' => $group->public_id,
            'severity' => $group->severity->value,
            'affected_monitors' => $affected,
            'notification_thread_key' => $group->notification_thread_key,
            'problem_filter' => [
                'route' => 'problems',
                'monitor_uuids' => $monitorIds,
            ],
            'opened_at' => $group->opened_at->toRfc3339String(),
            'emitted_at' => $emittedAt->toRfc3339String(),
            'downtime_seconds' => $downtimeSeconds,
        ];
    }
}
