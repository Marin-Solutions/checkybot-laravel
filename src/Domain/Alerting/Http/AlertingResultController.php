<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\MonitorResultIngestionInterface;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\NormalizedMonitorResult;

final readonly class AlertingResultController
{
    public function __construct(private MonitorResultIngestionInterface $ingestion) {}

    public function __invoke(Request $request): JsonResponse
    {
        $receipt = $this->ingestion->ingest(NormalizedMonitorResult::fromArray($request->all()));

        return response()->json($receipt->toArray(), $receipt->accepted ? 202 : 200);
    }
}
