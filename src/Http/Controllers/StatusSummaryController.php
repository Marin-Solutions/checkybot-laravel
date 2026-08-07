<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Http\Controllers;

use Illuminate\Http\Request;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Http\AuthenticateStatusSummaryToken;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Queries\StatusSummaryQuery;
use MarinSolutions\CheckybotLaravel\Http\Resources\MonitoringFoundation\StatusSummaryResource;
use MarinSolutions\CheckybotLaravel\Models\ProjectApiToken;

final readonly class StatusSummaryController
{
    public function __construct(private StatusSummaryQuery $query) {}

    public function __invoke(Request $request): StatusSummaryResource
    {
        /** @var ProjectApiToken $token */
        $token = $request->attributes->get(AuthenticateStatusSummaryToken::REQUEST_ATTRIBUTE);

        return new StatusSummaryResource($this->query->forProject($token->project_id));
    }
}
