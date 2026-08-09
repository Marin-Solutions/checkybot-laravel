<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NotificationSideEffectSnapshot
{
    /**
     * @return array{
     *   monitor_states:int,
     *   monitor_transitions:int,
     *   incident_groups:int,
     *   notification_intent_outbox:int,
     *   notification_intents:int,
     *   push_operations:int
     * }
     */
    public function capture(string $projectId): array
    {
        return [
            'monitor_states' => $this->projectCount('monitor_states', $projectId),
            'monitor_transitions' => $this->projectCount('monitor_transitions', $projectId),
            'incident_groups' => $this->projectCount('alerting_incident_groups', $projectId),
            'notification_intent_outbox' => $this->notificationOutboxCount($projectId),
            'notification_intents' => $this->projectCount('alerting_notification_intents', $projectId),
            'push_operations' => $this->projectCount('push_operations', $projectId),
        ];
    }

    private function projectCount(string $table, string $projectId): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return DB::table($table)->where('project_id', $projectId)->count();
    }

    private function notificationOutboxCount(string $projectId): int
    {
        if (! Schema::hasTable('outbox_events')) {
            return 0;
        }

        return DB::table('outbox_events')
            ->where('event_type', 'notification.intent.created')
            ->where('payload->project_uuid', $projectId)
            ->count();
    }
}
