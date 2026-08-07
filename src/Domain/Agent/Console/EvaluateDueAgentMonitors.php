<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent\Console;

use Illuminate\Console\Command;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Actions\ScanAgentDeadMan;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Jobs\EvaluateAgentReport;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\AgentReport;

final class EvaluateDueAgentMonitors extends Command
{
    protected $signature = 'checkybot:agent-evaluate-due {--limit=500}';

    protected $description = 'Queue unevaluated agent reports and evaluate due server reporting heartbeats';

    public function handle(ScanAgentDeadMan $deadMan): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $operationIds = AgentReport::query()
            ->whereDoesntHave('monitorEvaluation')
            ->orderBy('id')->limit($limit)->pluck('operation_id');

        foreach ($operationIds as $operationId) {
            EvaluateAgentReport::dispatch((string) $operationId);
        }

        $deadManCount = $deadMan->execute();
        $this->info(sprintf(
            'Queued %d agent report evaluation(s); submitted %d dead-man candidate(s).',
            $operationIds->count(),
            $deadManCount,
        ));

        return self::SUCCESS;
    }
}
