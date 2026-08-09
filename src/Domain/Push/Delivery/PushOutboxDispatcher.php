<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Push\Delivery;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery\FoundationEventDispatcher;
use MarinSolutions\CheckybotLaravel\Domain\Push\Jobs\ProcessNotificationIntent;
use MarinSolutions\CheckybotLaravel\Domain\Push\Models\PushOperation;
use MarinSolutions\CheckybotLaravel\Models\OutboxEvent;
use RuntimeException;

final readonly class PushOutboxDispatcher implements FoundationEventDispatcher
{
    public function __construct(private FoundationEventDispatcher $next) {}

    public function dispatch(OutboxEvent $event, array $sanitizedPayload): array
    {
        $receipts = $this->next->dispatch($event, $sanitizedPayload);
        if ($event->event_type !== 'notification.intent.created' || ! Schema::hasTable('push_operations')) {
            return $receipts;
        }
        $this->assertContract($sanitizedPayload, $event);

        $safe = [
            'problem_filter' => [
                'route' => 'problems',
                'monitor_uuids' => array_values(array_unique($sanitizedPayload['problem_filter']['monitor_uuids'])),
            ],
            'opened_at' => $sanitizedPayload['opened_at'],
            'emitted_at' => $sanitizedPayload['emitted_at'],
            'downtime_seconds' => $sanitizedPayload['downtime_seconds'] ?? null,
        ];
        // firstOrCreate uses Laravel's create-or-select unique-violation fallback,
        // so independent at-least-once listeners converge on the operation row.
        $operation = PushOperation::query()->firstOrCreate(
            ['operation_id' => $sanitizedPayload['operation_id']],
            [
                'project_id' => $sanitizedPayload['project_uuid'],
                'intent_id' => $sanitizedPayload['intent_id'],
                'group_id' => $sanitizedPayload['group_id'],
                'phase' => $sanitizedPayload['phase'],
                'severity' => $sanitizedPayload['severity'],
                'thread_key' => mb_substr($sanitizedPayload['notification_thread_key'], 0, 120),
                'payload' => $safe,
            ],
        );
        $created = $operation->wasRecentlyCreated;

        if ($created) {
            ProcessNotificationIntent::dispatch($operation->operation_id)->afterCommit();
        }
        $receipts[] = [
            'consumer' => 'registered-device-push',
            'contract_version' => 'notification-intent.v1',
            'consumer_ack' => $created ? 'queued' : 'duplicate',
            'operation_id' => $operation->operation_id,
            'delivered_at' => now()->toISOString(),
        ];

        return $receipts;
    }

    private function assertContract(array $payload, OutboxEvent $event): void
    {
        foreach (['operation_id', 'intent_id', 'project_uuid', 'group_id'] as $key) {
            if (! isset($payload[$key]) || ! is_string($payload[$key]) || ! Str::isUuid($payload[$key])) {
                throw new RuntimeException("Invalid notification intent {$key}.");
            }
        }
        if ($event->contract_version !== 'notification-intent.v1'
            || ($payload['contract_version'] ?? null) !== 'notification-intent.v1'
            || ! in_array($payload['phase'] ?? null, ['incident', 'recovery'], true)
            || ! in_array($payload['severity'] ?? null, ['warn', 'critical'], true)
            || ($payload['problem_filter']['route'] ?? null) !== 'problems'
            || ! is_array($payload['problem_filter']['monitor_uuids'] ?? null)
            || $payload['problem_filter']['monitor_uuids'] === []
            || ! isset($payload['notification_thread_key'], $payload['opened_at'], $payload['emitted_at'])) {
            throw new RuntimeException('Invalid notification-intent.v1 payload.');
        }
        foreach ($payload['problem_filter']['monitor_uuids'] as $uuid) {
            if (! is_string($uuid) || ! Str::isUuid($uuid)) {
                throw new RuntimeException('Invalid notification monitor filter.');
            }
        }
    }
}
