<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Http;

use Illuminate\Http\JsonResponse;
use MarinSolutions\CheckybotLaravel\Models\OutboxEvent;

final class FoundationReceiptController
{
    public function __invoke(string $operationId): JsonResponse
    {
        $event = OutboxEvent::query()->where('operation_id', $operationId)->first();

        if ($event === null) {
            return response()->json(['message' => 'Foundation harness operation not found.'], 404);
        }

        return response()->json([
            'operation_id' => $event->operation_id,
            'status' => in_array($event->status, ['pending', 'processing'], true) ? 'queued' : $event->status,
            'receipts' => $event->receipts ?? [],
            'sanitized_payload' => $event->sanitized_payload,
        ]);
    }
}
