<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Http;

use Illuminate\Http\JsonResponse;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\AlertingResult;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\PullRetryRequest;
use MarinSolutions\CheckybotLaravel\Models\MonitorState;
use MarinSolutions\CheckybotLaravel\Models\MonitorTransition;
use MarinSolutions\CheckybotLaravel\Models\OutboxEvent;

final class AlertingReceiptController
{
    public function __invoke(string $operationId): JsonResponse
    {
        $result = AlertingResult::query()->where('operation_id', $operationId)->first();
        if ($result === null) {
            return response()->json(['message' => 'Alerting operation not found.'], 404);
        }

        $identity = [
            'project_id' => $result->project_id,
            'monitor_id' => $result->monitor_id,
            'monitor_type' => $result->monitor_type,
        ];
        $state = MonitorState::query()->where($identity)->first();
        $transitions = MonitorTransition::query()->where($identity)->orderBy('operation_sequence')->get();
        $operationIds = $transitions->pluck('operation_id');
        $outbox = OutboxEvent::query()->whereIn('operation_id', $operationIds)->get();
        $consumerReceipts = $outbox->flatMap(static function (OutboxEvent $event): array {
            return collect($event->receipts ?? [])
                ->filter(static fn (array $receipt): bool => ($receipt['consumer'] ?? null) === 'agent')
                ->map(static fn (array $receipt): array => [
                    'consumer' => 'agent-v2-expanded-monitors',
                    'effect' => 'transition_persisted',
                    'processed_at' => $receipt['delivered_at'],
                ])->all();
        })->values()->all();

        if ($result->status !== 'failed') {
            array_unshift($consumerReceipts, [
                'consumer' => 'agent-v2-expanded-monitors',
                'effect' => 'ingestion_contract_accepted',
                'processed_at' => $result->created_at->toRfc3339String(),
            ]);
        }

        return response()->json([
            'operation_id' => $operationId,
            'status' => $result->status === 'processing' ? 'queued' : $result->status,
            'current_state' => $state?->state->value,
            'transitions' => $transitions->map(static fn (MonitorTransition $transition): array => [
                'transition_id' => $transition->public_id,
                'from' => $transition->from_state->value,
                'to' => $transition->to_state->value,
                'severity' => $transition->severity->value,
                'occurred_at' => $transition->occurred_at->toRfc3339String(),
                'entered_at' => $transition->entered_at?->toRfc3339String(),
                'reason_code' => $transition->reason_code,
                'group_id' => null,
                'maintenance_suppressed' => false,
            ])->all(),
            'scheduled_retries' => PullRetryRequest::query()
                ->where('project_id', $result->project_id)
                ->where('monitor_id', $result->monitor_id)
                ->where('monitor_type', $result->monitor_type)
                ->whereNull('canceled_at')
                ->orderBy('due_at')->get()->map(static fn (PullRetryRequest $retry): string => $retry->due_at->toRfc3339String())->all(),
            'incident_groups' => [],
            'notification_intents' => [],
            'consumer_receipts' => $consumerReceipts,
        ]);
    }
}
