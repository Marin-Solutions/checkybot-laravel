<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Contracts\RedactedLogSnippetProvider;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Data\AuthorizedLogSnippetRequest;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Data\RedactedLogSnippet;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\RegisteredServer;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorType;
use MarinSolutions\CheckybotLaravel\Domain\Security\Foundation\RecursiveRedactor;

final readonly class EloquentRedactedLogSnippetProvider implements RedactedLogSnippetProvider
{
    private const SOURCES = ['nginx', 'fpm', 'mysql'];

    public function __construct(private RecursiveRedactor $redactor) {}

    public function forIncident(AuthorizedLogSnippetRequest $request): RedactedLogSnippet
    {
        $denial = $this->denialReason($request);
        if ($denial !== null) {
            return $this->denied($denial);
        }

        $server = RegisteredServer::query()
            ->where('server_uuid', $request->identity->monitorId)
            ->where('project_id', $request->identity->projectId)
            ->where('enabled', true)
            ->first();
        if ($server === null) {
            return $this->denied('unauthorized');
        }
        if (! $server->share_redacted_logs) {
            return $this->denied('disabled');
        }

        $rows = DB::table('agent_redacted_log_lines as lines')
            ->join('agent_reports as reports', 'reports.id', '=', 'lines.agent_report_id')
            ->where('reports.agent_server_id', $server->getKey())
            ->where('reports.project_id', $request->identity->projectId)
            ->whereIn('lines.source', $request->sources)
            ->whereBetween('lines.observed_at', [$request->from, $request->to])
            ->orderBy('lines.observed_at')
            ->orderBy('lines.id')
            ->limit($request->limit + 1)
            ->get(['lines.source', 'lines.observed_at', 'lines.redacted_line']);

        $truncated = $rows->count() > $request->limit;
        $lines = $rows->take($request->limit)->map(fn (object $row): array => [
            'source' => (string) $row->source,
            'observed_at' => CarbonImmutable::parse((string) $row->observed_at)->utc()->toRfc3339String(),
            'redacted_line' => (string) $this->redactor->redact((string) $row->redacted_line),
        ])->values()->all();

        return new RedactedLogSnippet(lines: $lines, truncated: $truncated);
    }

    private function denialReason(AuthorizedLogSnippetRequest $request): ?string
    {
        if (! hash_equals($request->authorizedProjectId, $request->identity->projectId)) {
            return 'unauthorized';
        }
        if ($request->identity->type !== MonitorType::Server) {
            return 'unsupported-identity';
        }
        if ($request->limit < 1 || $request->limit > 200) {
            return 'invalid-limit';
        }
        if ($request->from->greaterThan($request->to)) {
            return 'out-of-window';
        }
        if ($request->sources === [] || array_diff($request->sources, self::SOURCES) !== []) {
            return 'unsupported-source';
        }

        return null;
    }

    private function denied(string $reason): RedactedLogSnippet
    {
        return new RedactedLogSnippet(denied: true, denialReason: $reason);
    }
}
