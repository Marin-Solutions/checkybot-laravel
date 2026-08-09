<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Contracts\RedactedLogSnippetProvider;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Data\AuthorizedLogSnippetRequest;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\AgentReport;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\RegisteredServer;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorIdentity;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorType;
use MarinSolutions\CheckybotLaravel\Domain\Security\Foundation\RecursiveRedactor;

function snippetRequest(
    RegisteredServer $server,
    string $authorizedProjectId,
    array $sources = ['nginx', 'fpm', 'mysql'],
    int $limit = 200,
    ?CarbonImmutable $from = null,
    ?CarbonImmutable $to = null,
    ?string $identityProjectId = null,
): AuthorizedLogSnippetRequest {
    return new AuthorizedLogSnippetRequest(
        identity: new MonitorIdentity(
            $identityProjectId ?? $server->project_id,
            $server->server_uuid,
            MonitorType::Server,
        ),
        authorizedProjectId: $authorizedProjectId,
        from: $from ?? CarbonImmutable::parse('2026-08-07T11:55:00Z'),
        to: $to ?? CarbonImmutable::parse('2026-08-07T12:05:00Z'),
        sources: $sources,
        limit: $limit,
    );
}

/** @param list<array{source: string, observed_at: string, redacted_line: string}> $lines */
function persistSnippetLines(RegisteredServer $server, array $lines): AgentReport
{
    $report = AgentReport::query()->create([
        'operation_id' => (string) Str::uuid(),
        'agent_server_id' => $server->getKey(),
        'project_id' => $server->project_id,
        'payload_hash' => str_repeat('a', 64),
        'schema_version' => 'agent-report.v2',
        'agent_version' => '2.0.0',
        'observed_at' => '2026-08-07T12:00:00Z',
        'reporting_interval_seconds' => 60,
        'cpu_five_min_percent' => 10,
        'memory_used_percent' => 20,
        'nginx_window_seconds' => 300,
        'nginx_total_requests' => 0,
        'nginx_five_xx_count' => 0,
        'nginx_upstream_timeout_count' => 0,
        'payload' => [],
    ]);
    $report->relevantLogLines()->createMany($lines);

    return $report;
}

beforeEach(function (): void {
    config()->set('database.default', 'agent_snippet_test');
    config()->set('database.connections.agent_snippet_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('checkybot.monitor_foundation.redaction.secret_literals', ['configured-read-secret']);
    app()->forgetInstance(RecursiveRedactor::class);
    DB::purge('agent_snippet_test');
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_000000_create_monitor_foundation_tables.php')->up();
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_010000_create_alerting_result_runtime_tables.php')->up();
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_010100_create_alerting_incident_group_tables.php')->up();
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_030000_create_agent_v2_report_runtime_tables.php')->up();
});

afterEach(function (): void {
    DB::disconnect('agent_snippet_test');
});

it('denies disabled, unauthorized, cross-project, unsupported-source, and out-of-window reads', function (): void {
    $project = (string) Str::uuid();
    $otherProject = (string) Str::uuid();
    $server = RegisteredServer::register($project);
    persistSnippetLines($server, [[
        'source' => 'nginx',
        'observed_at' => '2026-08-07T12:00:00Z',
        'redacted_line' => 'relevant context',
    ]]);
    $provider = app(RedactedLogSnippetProvider::class);

    $disabled = $provider->forIncident(snippetRequest($server, $project));
    $server->forceFill(['share_redacted_logs' => true])->save();
    $unauthorized = $provider->forIncident(snippetRequest($server, $otherProject));
    $crossProject = $provider->forIncident(snippetRequest(
        $server,
        $otherProject,
        identityProjectId: $otherProject,
    ));
    $unsupported = $provider->forIncident(snippetRequest($server, $project, ['nginx', 'syslog']));
    $outOfWindow = $provider->forIncident(snippetRequest(
        $server,
        $project,
        from: CarbonImmutable::parse('2026-08-07T13:00:00Z'),
        to: CarbonImmutable::parse('2026-08-07T13:05:00Z'),
    ));

    expect($disabled->lines)->toBe([])
        ->and($disabled->denied)->toBeTrue()
        ->and($unauthorized->lines)->toBe([])
        ->and($unauthorized->denied)->toBeTrue()
        ->and($crossProject->lines)->toBe([])
        ->and($crossProject->denied)->toBeTrue()
        ->and($unsupported->lines)->toBe([])
        ->and($unsupported->denied)->toBeTrue()
        ->and($outOfWindow->lines)->toBe([])
        ->and($disabled->alertEligible)->toBeFalse()
        ->and($unauthorized->alertEligible)->toBeFalse()
        ->and($crossProject->alertEligible)->toBeFalse()
        ->and($unsupported->alertEligible)->toBeFalse()
        ->and($outOfWindow->alertEligible)->toBeFalse();
})->group('AC-agent-v2-expanded-monitors-14', 'AC-agent-v2-expanded-monitors-15');

it('returns opted-in lines in deterministic timestamp order at the limit with recursive read-time redaction', function (): void {
    $project = (string) Str::uuid();
    $server = RegisteredServer::register($project);
    $server->forceFill(['share_redacted_logs' => true])->save();
    persistSnippetLines($server, [
        ['source' => 'mysql', 'observed_at' => '2026-08-07T12:00:03Z', 'redacted_line' => 'contact operator@example.test from 192.0.2.44'],
        ['source' => 'nginx', 'observed_at' => '2026-08-07T12:00:01Z', 'redacted_line' => 'GET /callback?code=query-value'],
        ['source' => 'fpm', 'observed_at' => '2026-08-07T12:00:02Z', 'redacted_line' => 'Authorization: Bearer credential-value'],
        ['source' => 'nginx', 'observed_at' => '2026-08-07T12:00:02Z', 'redacted_line' => 'Cookie: session=cookie-value'],
        ['source' => 'mysql', 'observed_at' => '2026-08-07T12:00:04Z', 'redacted_line' => 'configured-read-secret'],
    ]);

    $result = app(RedactedLogSnippetProvider::class)->forIncident(snippetRequest($server, $project, limit: 4));
    $encoded = json_encode($result, JSON_THROW_ON_ERROR);

    expect($result->lines)->toHaveCount(4)
        ->and(array_column($result->lines, 'observed_at'))->toBe([
            '2026-08-07T12:00:01+00:00',
            '2026-08-07T12:00:02+00:00',
            '2026-08-07T12:00:02+00:00',
            '2026-08-07T12:00:03+00:00',
        ])
        ->and(array_column($result->lines, 'source'))->toBe(['nginx', 'fpm', 'nginx', 'mysql'])
        ->and($result->truncated)->toBeTrue()
        ->and($result->alertEligible)->toBeFalse()
        ->and($result->redactionVersion)->toBe('foundation-recursive.v1')
        ->and($encoded)->toContain('code=[REDACTED]', 'Authorization: [REDACTED]', 'Cookie: [REDACTED]')
        ->and($encoded)->not->toContain(
            'query-value',
            'credential-value',
            'cookie-value',
            'configured-read-secret',
            'operator@example.test',
            '192.0.2.44',
        );
})->group('AC-agent-v2-expanded-monitors-14');

it('does not mutate alerting state or create incident delivery side effects for successful or denied reads', function (): void {
    $project = (string) Str::uuid();
    $server = RegisteredServer::register($project);
    persistSnippetLines($server, [[
        'source' => 'nginx',
        'observed_at' => '2026-08-07T12:00:00Z',
        'redacted_line' => 'upstream timeout context',
    ]]);
    $tables = [
        'monitor_states',
        'monitor_transitions',
        'alerting_incident_groups',
        'outbox_events',
        'alerting_notification_intents',
    ];
    $countsBefore = array_combine($tables, array_map(static fn (string $table): int => DB::table($table)->count(), $tables));
    $provider = app(RedactedLogSnippetProvider::class);

    $denied = $provider->forIncident(snippetRequest($server, $project));
    $server->forceFill(['share_redacted_logs' => true])->save();
    $successful = $provider->forIncident(snippetRequest($server, $project));
    $countsAfter = array_combine($tables, array_map(static fn (string $table): int => DB::table($table)->count(), $tables));

    expect($denied->alertEligible)->toBeFalse()
        ->and($successful->alertEligible)->toBeFalse()
        ->and($successful->lines)->toHaveCount(1)
        ->and($countsAfter)->toBe($countsBefore);
})->group('AC-agent-v2-expanded-monitors-15');

it('documents executable agent v2 fleet preflight, rollout, rollback, and every prerequisite remediation', function (): void {
    $guide = file_get_contents(dirname(__DIR__, 3).'/docs/agent-v2-rollout.md');

    expect($guide)->toBeString();
    foreach ([
        'nginx -T', 'access_log', 'adm', 'sudo -n test -r', 'php-fpm', 'mysql',
        'stat -c', '0600', '1,000,000,000', 'OverrideServerLinkCap', 'agent:report',
        'rotate', 'canary', 'agent_version', 'rollback', 'readable', 'missing',
        'permission_denied', 'disabled', 'not_configured',
    ] as $required) {
        expect($guide)->toContain($required);
    }
    expect(substr_count($guide, '```bash'))->toBeGreaterThanOrEqual(8);
})->group('AC-agent-v2-expanded-monitors-16');
