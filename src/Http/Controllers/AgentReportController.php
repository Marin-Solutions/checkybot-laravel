<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Actions\IngestAgentReport;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Exceptions\AgentReportForbidden;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Exceptions\AgentReportOperationCollision;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Http\AuthenticateAgentReportToken;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Http\StoreAgentReportRequest;
use MarinSolutions\CheckybotLaravel\Models\ProjectApiToken;

final readonly class AgentReportController
{
    public function __construct(private IngestAgentReport $ingest) {}

    public function __invoke(StoreAgentReportRequest $request): JsonResponse
    {
        $token = $request->attributes->get(AuthenticateAgentReportToken::REQUEST_ATTRIBUTE);
        if (! $token instanceof ProjectApiToken) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        try {
            $receipt = $this->ingest->execute($token, $request->reportData());
        } catch (AgentReportForbidden) {
            return new JsonResponse(['message' => 'The token cannot report for this server.'], 403);
        } catch (AgentReportOperationCollision) {
            return new JsonResponse(['message' => 'The operation_id is already bound to a different immutable report.'], 409);
        }

        return new JsonResponse($receipt->toArray(), $receipt->created ? 202 : 200);
    }
}
