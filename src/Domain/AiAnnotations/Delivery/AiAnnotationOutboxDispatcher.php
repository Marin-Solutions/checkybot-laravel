<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Delivery;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Actions\NotificationSideEffectSnapshot;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiAnnotationOperation;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiProjectSetting;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\FoundationContract;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery\FoundationEventDispatcher;
use MarinSolutions\CheckybotLaravel\Jobs\AiAnnotations\GenerateIncidentAnnotation;
use MarinSolutions\CheckybotLaravel\Models\MonitorTransition;
use MarinSolutions\CheckybotLaravel\Models\OutboxEvent;

final readonly class AiAnnotationOutboxDispatcher implements FoundationEventDispatcher
{
    public function __construct(
        private FoundationEventDispatcher $next,
        private NotificationSideEffectSnapshot $sideEffects,
    ) {}

    public function dispatch(OutboxEvent $event, array $sanitizedPayload): array
    {
        $receipts = $this->next->dispatch($event, $sanitizedPayload);
        if ($event->event_type !== 'monitor.transitioned' || ! Schema::hasTable('ai_annotation_operations')) {
            return $receipts;
        }

        [$reason, $transition] = $this->eligibility($event, $sanitizedPayload);
        if ($reason !== null || $transition === null) {
            $receipts[] = $this->receipt($event, 'skipped', $reason);

            return $receipts;
        }

        $created = false;
        try {
            $operation = AiAnnotationOperation::query()->firstOrCreate(
                ['operation_id' => $event->operation_id],
                [
                    'transition_operation_id' => $transition->operation_id,
                    'project_id' => $transition->project_id,
                    'status' => 'queued',
                    'notification_side_effects' => [
                        'before' => $this->sideEffects->capture($transition->project_id),
                        'after' => null,
                    ],
                ],
            );
            $created = $operation->wasRecentlyCreated;
        } catch (QueryException $exception) {
            $operation = AiAnnotationOperation::query()->where('transition_operation_id', $transition->operation_id)->first();
            if ($operation === null) {
                throw $exception;
            }
        }

        if (! hash_equals($operation->project_id, $transition->project_id)
            || ! hash_equals($operation->transition_operation_id, $transition->operation_id)) {
            $receipts[] = $this->receipt($event, 'skipped', 'missing_transition');

            return $receipts;
        }

        if ($created) {
            GenerateIncidentAnnotation::dispatch($event->operation_id)->afterCommit();
        }
        $receipts[] = $this->receipt($event, $created ? 'queued' : 'duplicate');

        return $receipts;
    }

    /** @return array{0: ?string, 1: ?MonitorTransition} */
    private function eligibility(OutboxEvent $event, array $payload): array
    {
        $identity = $payload['identity'] ?? null;
        if (($event->contract_version !== FoundationContract::VERSION)
            || ! is_array($identity)
            || ! is_string($identity['project_id'] ?? null)
            || ! is_string($identity['monitor_id'] ?? null)
            || ! Str::isUuid($event->operation_id)
            || ! Str::isUuid($identity['project_id'])
            || ! Str::isUuid($identity['monitor_id'])) {
            return ['missing_transition', null];
        }
        if (($payload['to_state'] ?? null) !== 'down') {
            return ['ineligible_state', null];
        }
        if (($identity['type'] ?? null) !== 'server') {
            return ['unsupported_monitor', null];
        }

        $transition = MonitorTransition::query()
            ->where('operation_id', $event->operation_id)
            ->where('project_id', strtolower($identity['project_id']))
            ->where('monitor_id', strtolower($identity['monitor_id']))
            ->where('monitor_type', 'server')
            ->where('to_state', 'down')
            ->first();
        $payloadOccurredAt = $payload['occurred_at'] ?? null;
        if ($transition === null
            || $transition->from_state->value !== ($payload['from_state'] ?? null)
            || $transition->severity->value !== ($payload['severity'] ?? null)
            || ! is_string($payloadOccurredAt)
            || ! hash_equals($transition->occurred_at->toRfc3339String(), $payloadOccurredAt)) {
            return ['missing_transition', null];
        }

        $enabled = AiProjectSetting::query()->forProject($transition->project_id)->where('enabled', true)->exists();

        return $enabled ? [null, $transition] : ['disabled', null];
    }

    /** @return array<string, mixed> */
    private function receipt(OutboxEvent $event, string $ack, ?string $reason = null): array
    {
        return [
            'consumer' => 'ai-incident-annotations',
            'contract_version' => FoundationContract::VERSION,
            'consumer_ack' => $ack,
            'operation_id' => $event->operation_id,
            'skip_reason' => $reason,
            'delivered_at' => now()->toISOString(),
        ];
    }
}
