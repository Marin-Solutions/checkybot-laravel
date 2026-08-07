<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent\Actions;

use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\AgentReport;

final readonly class PrepareAgentReportEvaluation
{
    public function execute(string $operationId): void
    {
        $report = AgentReport::query()->with('server')->where('operation_id', $operationId)->firstOrFail();
        $report->forceFill([
            'evaluation_link_cap_bps' => $report->server->link_cap_bps,
            'evaluation_prepared_at' => now(),
        ])->save();
    }
}
