<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Push\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use MarinSolutions\CheckybotLaravel\Domain\Push\Models\PushDeliveryAttempt;
use MarinSolutions\CheckybotLaravel\Domain\Push\Models\PushOperation;

final class PushReceiptController
{
    public function __invoke(string $operationId): JsonResponse
    {
        $operation = PushOperation::query()->where('operation_id', $operationId)->first();
        if ($operation === null) {
            return new JsonResponse(['message' => 'Push operation not found.'], 404);
        }
        $attempts = PushDeliveryAttempt::query()->where('operation_id', $operationId)->orderBy('id')->get();
        $expo = $attempts->where('channel', 'expo')->map(static function (PushDeliveryAttempt $attempt): array {
            $payload = $attempt->payload_snapshot ?? [];

            return [
                'device_id' => $attempt->device_id,
                'status' => $attempt->status,
                'ticket_id' => $attempt->ticket_id,
                'priority' => $payload['priority'] ?? ($attempt->status === 'queued' ? 'default' : null),
                'sound' => $payload['sound'] ?? null,
                'interruption_level' => $payload['interruptionLevel'] ?? null,
                'refresh_widget' => (bool) ($payload['data']['refreshWidget'] ?? false),
            ];
        })->values()->all();
        $legacyAttempt = $attempts->firstWhere('channel', 'legacy_webhook');
        $legacy = $operation->severity !== 'critical'
            ? 'not_required'
            : ($legacyAttempt?->status === 'accepted' ? 'accepted' : 'failed');
        $requiredReceipts = $operation->severity === 'critical' ? 2 : 0;
        $recorded = $requiredReceipts === 0 || DB::table('push_reliability_receipts')
            ->where('operation_id', $operationId)->count() === $requiredReceipts;

        return new JsonResponse([
            'operation_id' => $operation->operation_id,
            'listener_status' => $operation->status,
            'expo_deliveries' => $expo,
            'legacy_webhook' => $legacy,
            'reliability_recorded' => $recorded,
            'processed_at' => $operation->processed_at?->toRfc3339String(),
        ]);
    }
}
