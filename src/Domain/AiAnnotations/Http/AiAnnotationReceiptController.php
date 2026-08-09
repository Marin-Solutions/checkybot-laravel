<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Http;

use Illuminate\Http\JsonResponse;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiAnnotationOperation;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiBudgetBucket;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiBudgetReservation;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiIncidentAnnotation;

final class AiAnnotationReceiptController
{
    public function __invoke(string $operationId): JsonResponse
    {
        $operation = AiAnnotationOperation::query()->where('operation_id', $operationId)->first();
        if ($operation === null) {
            return response()->json(['message' => 'AI annotation operation not found.'], 404);
        }

        $reservation = AiBudgetReservation::query()->where('operation_id', $operationId)->first();
        $reservedMicrousd = 0;
        $spentMicrousd = 0;
        if ($reservation !== null) {
            $bucket = AiBudgetBucket::query()
                ->where('scope_key', 'project:'.$operation->project_id)
                ->where('period', $reservation->period)
                ->first();
            if ($bucket !== null) {
                $reservedMicrousd = max(0, (int) $bucket->reserved_microusd);
                $spentMicrousd = max(0, (int) $bucket->spent_microusd);
            }
        }
        $annotation = AiIncidentAnnotation::query()->where('operation_id', $operationId)->first();

        return response()->json([
            'operation_id' => $operation->operation_id,
            'status' => $operation->status,
            'skip_reason' => $operation->skip_reason,
            'snippet' => [
                'line_count' => (int) $operation->snippet_line_count,
                'truncated' => (bool) $operation->snippet_truncated,
                'redaction_version' => $operation->redaction_version,
            ],
            'budget' => [
                'reserved_microusd' => $reservedMicrousd,
                'spent_microusd' => $spentMicrousd,
            ],
            'annotation' => [
                'root_cause' => $annotation?->root_cause,
                'generated_at' => $annotation?->generated_at?->utc()->toRfc3339String(),
            ],
            'notification_side_effects' => $operation->notification_side_effects ?? [
                'before' => null,
                'after' => null,
            ],
        ]);
    }
}
