<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Contracts\AiAnnotationProvider;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Data\ProviderFailureCode;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Data\ProviderRequest;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Data\ProviderResult;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiAnnotationOperation;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiIncidentAnnotation;

final readonly class GenerateIncidentAnnotation
{
    public function __construct(
        private BudgetLedger $budgets,
        private AiAnnotationProvider $provider,
    ) {}

    /** @param list<array{source:string,observed_at:string,redacted_line:string}> $lines */
    public function execute(
        string $operationId,
        string $transitionOperationId,
        string $projectId,
        array $lines,
        bool $truncated = false,
        string $redactionVersion = 'foundation-recursive.v1',
        ?CarbonImmutable $at = null,
    ): ProviderResult {
        $projectId = strtolower($projectId);
        $operation = AiAnnotationOperation::query()->firstOrCreate(
            ['operation_id' => $operationId],
            [
                'transition_operation_id' => $transitionOperationId,
                'project_id' => $projectId,
                'status' => 'processing',
                'snippet_line_count' => min(65535, count($lines)),
                'snippet_truncated' => $truncated,
                'redaction_version' => $redactionVersion,
            ],
        );
        if (! hash_equals($operation->project_id, $projectId)
            || ! hash_equals($operation->transition_operation_id, $transitionOperationId)
            || $operation->status !== 'processing') {
            return ProviderResult::failure(ProviderFailureCode::InvalidResponse);
        }

        $reservation = $this->budgets->reserve($operationId, $projectId, $at);
        if (! $reservation->allowed || ! $reservation->acquired || $reservation->reservation === null) {
            AiAnnotationOperation::query()->forProject($projectId)->where('operation_id', $operationId)
                ->update(['status' => $reservation->allowed ? 'skipped' : 'failed', 'skip_reason' => 'over_reservation']);

            return ProviderResult::failure(ProviderFailureCode::OverReservation);
        }

        $result = $this->provider->annotate(new ProviderRequest(
            operationId: $operationId,
            lines: $lines,
            reservationMicrousd: $reservation->reservation->reserved_microusd,
        ));
        if (! $result->successful || $result->probableCause === null) {
            $this->budgets->release($operationId);
            AiAnnotationOperation::query()->forProject($projectId)->where('operation_id', $operationId)
                ->update(['status' => 'failed', 'skip_reason' => $result->failure?->value]);

            return $result;
        }

        DB::transaction(function () use ($operationId, $transitionOperationId, $projectId, $result, $at): void {
            if (! $this->budgets->settle($operationId, $result->billedMicrousd)) {
                return;
            }
            AiIncidentAnnotation::query()->create([
                'operation_id' => $operationId,
                'transition_operation_id' => $transitionOperationId,
                'project_id' => $projectId,
                'root_cause' => $result->probableCause,
                'generated_at' => ($at ?? CarbonImmutable::now('UTC'))->utc(),
            ]);
            AiAnnotationOperation::query()->forProject($projectId)->where('operation_id', $operationId)
                ->update(['status' => 'completed', 'skip_reason' => null]);
        }, 5);

        return $result;
    }
}
