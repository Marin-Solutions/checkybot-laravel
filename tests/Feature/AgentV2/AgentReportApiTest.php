<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Actions\OverrideServerLinkCap;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Actions\PrepareAgentReportEvaluation;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Jobs\EvaluateAgentReport;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\AgentReport;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\RegisteredServer;
use MarinSolutions\CheckybotLaravel\Models\ProjectApiToken;

function validAgentReportPayload(string $serverUuid, ?string $operationId = null): array
{
    return [
        'schema_version' => 'agent-report.v2',
        'operation_id' => $operationId ?? (string) Str::uuid(),
        'agent_version' => '2.4.1',
        'server_uuid' => $serverUuid,
        'observed_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.u\Z'),
        'reporting_interval_seconds' => 60,
        'cpu' => ['five_min_percent' => 42.5],
        'memory' => ['used_percent' => 63.25],
        'disks' => [
            ['mount' => '/', 'used_percent' => 71.2, 'predicted_days_to_full' => 18.5],
        ],
        'network_interfaces' => [
            [
                'name' => 'eth0', 'rx_bytes_total' => 1100, 'tx_bytes_total' => 2200,
                'rx_delta_bytes' => 100, 'tx_delta_bytes' => 200, 'elapsed_seconds' => 60.25,
                'sample_status' => 'ready',
            ],
            [
                'name' => 'eth1', 'rx_bytes_total' => 50, 'tx_bytes_total' => 80,
                'rx_delta_bytes' => null, 'tx_delta_bytes' => null, 'elapsed_seconds' => null,
                'sample_status' => 'baseline',
            ],
        ],
        'php_fpm_pools' => [
            ['pool' => 'www', 'active_workers' => 9, 'max_children' => 20, 'max_children_reached_5m' => 2],
        ],
        'nginx_window' => [
            'window_seconds' => 300,
            'total_requests' => 125,
            'five_xx_count' => 7,
            'upstream_timeout_count' => 3,
        ],
        'prerequisites' => [
            ['kind' => 'nginx_access_log', 'path_hint' => '/var/log/nginx/access.log', 'status' => 'readable'],
            ['kind' => 'nginx_error_log', 'path_hint' => '/var/log/nginx/error.log', 'status' => 'missing'],
            ['kind' => 'php_fpm_status', 'path_hint' => '/run/php/status', 'status' => 'permission_denied'],
            ['kind' => 'php_fpm_log', 'path_hint' => '/var/log/php/fpm.log', 'status' => 'disabled'],
            ['kind' => 'mysql_log', 'path_hint' => '/var/log/mysql/error.log', 'status' => 'not_configured'],
        ],
        'relevant_log_lines' => [
            [
                'source' => 'nginx',
                'observed_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.u\Z'),
                'line' => 'GET /callback?token=[REDACTED] client=[REDACTED]',
            ],
        ],
    ];
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-07T12:00:00Z');
    config()->set('database.default', 'agent_v2_test');
    config()->set('database.connections.agent_v2_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('checkybot.monitor_foundation.redaction.secret_literals', ['configured-agent-secret']);
    DB::purge('agent_v2_test');
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_000000_create_monitor_foundation_tables.php')->up();
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_030000_create_agent_v2_report_runtime_tables.php')->up();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    DB::disconnect('agent_v2_test');
});

it('enforces token ability and project-scoped enabled server authorization', function (): void {
    $project = (string) Str::uuid();
    $otherProject = (string) Str::uuid();
    $server = RegisteredServer::register($project);
    $payload = validAgentReportPayload($server->server_uuid);

    $this->postJson('/api/v2/agent-reports', $payload)
        ->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    $this->withToken('invalid-token')->postJson('/api/v2/agent-reports', $payload)
        ->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);

    $missingAbility = ProjectApiToken::issue($project, 'read-only', ['status:read']);
    $this->withToken($missingAbility->plainTextToken())->postJson('/api/v2/agent-reports', $payload)
        ->assertForbidden()->assertExactJson(['message' => 'The token cannot report for this server.']);

    $otherToken = ProjectApiToken::issue($otherProject, 'agent', ['agent:report']);
    $this->withToken($otherToken->plainTextToken())->postJson('/api/v2/agent-reports', $payload)
        ->assertForbidden()->assertExactJson(['message' => 'The token cannot report for this server.']);

    $payload['server_uuid'] = (string) Str::uuid();
    $projectToken = ProjectApiToken::issue($project, 'agent', ['agent:report']);
    $this->withToken($projectToken->plainTextToken())->postJson('/api/v2/agent-reports', $payload)
        ->assertForbidden()->assertExactJson(['message' => 'The token cannot report for this server.']);

    $server->forceFill(['enabled' => false])->save();
    $payload['server_uuid'] = $server->server_uuid;
    $this->withToken($projectToken->plainTextToken())->postJson('/api/v2/agent-reports', $payload)
        ->assertForbidden();
})->group('AC-agent-v2-expanded-monitors-2');

it('rejects malformed, unknown-version, unknown-field, stale, and inconsistent reports', function (): void {
    $project = (string) Str::uuid();
    $server = RegisteredServer::register($project);
    $token = ProjectApiToken::issue($project, 'agent', ['agent:report']);
    $payload = validAgentReportPayload($server->server_uuid);

    $this->withToken($token->plainTextToken())->postJson('/api/v2/agent-reports', [])->assertUnprocessable();

    $readyInterface = $payload['network_interfaces'][0];
    $cases = [
        'schema_version' => ['schema_version' => 'agent-report.v3'],
        'unexpected' => ['unexpected' => true],
        'cpu' => ['cpu' => ['five_min_percent' => 42.5, 'unexpected' => true]],
        'cpu.five_min_percent' => ['cpu' => ['five_min_percent' => 101]],
        'observed_at' => ['observed_at' => CarbonImmutable::now()->subMinutes(6)->format('Y-m-d\\TH:i:s\\Z')],
        'network_interfaces.0.rx_bytes_total' => ['network_interfaces' => [[...$readyInterface, 'rx_bytes_total' => -1]]],
        'network_interfaces.0.sample_status' => ['network_interfaces' => [[
            ...$readyInterface,
            'rx_delta_bytes' => null, 'tx_delta_bytes' => null, 'elapsed_seconds' => null,
        ]]],
        'network_interfaces.1.name' => ['network_interfaces' => [$readyInterface, $readyInterface]],
        'nginx_window.window_seconds' => ['nginx_window' => [...$payload['nginx_window'], 'window_seconds' => 60]],
        'relevant_log_lines' => ['relevant_log_lines' => array_fill(0, 201, $payload['relevant_log_lines'][0])],
    ];
    foreach ($cases as $errorKey => $replacement) {
        $this->withToken($token->plainTextToken())
            ->postJson('/api/v2/agent-reports', array_replace($payload, $replacement))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errorKey);
    }
    $this->withToken($token->plainTextToken())->postJson('/api/v2/agent-reports', [
        ...$payload,
        'observed_at' => CarbonImmutable::now()->addMinutes(2)->format('Y-m-d\\TH:i:s\\Z'),
    ])->assertUnprocessable()->assertJsonValidationErrors('observed_at');
})->group('AC-agent-v2-expanded-monitors-2');

it('atomically stores all v2 samples, queues once, replays idempotently, and rejects collisions', function (): void {
    Queue::fake();
    $project = (string) Str::uuid();
    $server = RegisteredServer::register($project);
    $token = ProjectApiToken::issue($project, 'agent', ['agent:report']);
    $payload = validAgentReportPayload($server->server_uuid);

    $accepted = $this->withToken($token->plainTextToken())->postJson('/api/v2/agent-reports', $payload)
        ->assertAccepted()
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.operation_id', $payload['operation_id'])
        ->assertJsonPath('data.server_uuid', $server->server_uuid)
        ->assertJsonCount(1, 'data.evaluation_operation_ids');

    expect($accepted->json('data.evaluation_operation_ids.0'))->toBeUuid()
        ->and(DB::table('agent_reports')->count())->toBe(1)
        ->and(DB::table('agent_disk_samples')->count())->toBe(1)
        ->and(DB::table('agent_network_samples')->count())->toBe(2)
        ->and(DB::table('agent_php_fpm_samples')->count())->toBe(1)
        ->and(DB::table('agent_prerequisites')->count())->toBe(5)
        ->and(DB::table('agent_redacted_log_lines')->count())->toBe(1);
    Queue::assertPushed(EvaluateAgentReport::class, 1);

    $this->withToken($token->plainTextToken())->postJson('/api/v2/agent-reports', $payload)
        ->assertOk()->assertJsonPath('data.status', 'duplicate');
    expect(DB::table('agent_reports')->count())->toBe(1);
    Queue::assertPushed(EvaluateAgentReport::class, 1);

    $collision = $payload;
    $collision['cpu']['five_min_percent'] = 43.5;
    $this->withToken($token->plainTextToken())->postJson('/api/v2/agent-reports', $collision)
        ->assertStatus(409)
        ->assertExactJson(['message' => 'The operation_id is already bound to a different immutable report.']);
    expect(DB::table('agent_reports')->count())->toBe(1);
    Queue::assertPushed(EvaluateAgentReport::class, 1);
})->group('AC-agent-v2-expanded-monitors-2');

it('preserves parser aggregates and every prerequisite status without persisting sensitive log text', function (): void {
    Queue::fake();
    $project = (string) Str::uuid();
    $server = RegisteredServer::register($project);
    $token = ProjectApiToken::issue($project, 'agent', ['agent:report']);
    $payload = validAgentReportPayload($server->server_uuid);

    $this->withToken($token->plainTextToken())->postJson('/api/v2/agent-reports', $payload)->assertAccepted();
    $report = AgentReport::query()->firstOrFail();

    expect($report->phpFpmPools()->first()->only(['pool', 'active_workers', 'max_children', 'max_children_reached_5m']))
        ->toBe(['pool' => 'www', 'active_workers' => 9, 'max_children' => 20, 'max_children_reached_5m' => 2])
        ->and($report->only(['nginx_window_seconds', 'nginx_total_requests', 'nginx_five_xx_count', 'nginx_upstream_timeout_count']))
        ->toMatchArray(['nginx_window_seconds' => 300, 'nginx_total_requests' => 125, 'nginx_five_xx_count' => 7, 'nginx_upstream_timeout_count' => 3])
        ->and($report->prerequisites()->orderBy('id')->pluck('status')->all())
        ->toBe(['readable', 'missing', 'permission_denied', 'disabled', 'not_configured']);

    $unredacted = validAgentReportPayload($server->server_uuid);
    $unredacted['relevant_log_lines'][0]['line'] = 'GET /?token=query-value Authorization: Bearer credential Cookie: sid=cookie configured-agent-secret operator@example.test 192.0.2.44';
    $this->withToken($token->plainTextToken())->postJson('/api/v2/agent-reports', $unredacted)
        ->assertUnprocessable()->assertJsonValidationErrors('relevant_log_lines.0.line');

    $unredactedDiagnostic = validAgentReportPayload($server->server_uuid);
    $unredactedDiagnostic['prerequisites'][0]['path_hint'] = '/hosts/192.0.2.44/operator@example.test/configured-agent-secret';
    $this->withToken($token->plainTextToken())->postJson('/api/v2/agent-reports', $unredactedDiagnostic)
        ->assertUnprocessable()->assertJsonValidationErrors('prerequisites.0.path_hint');

    $persisted = json_encode([
        DB::table('agent_reports')->pluck('payload')->all(),
        DB::table('agent_redacted_log_lines')->pluck('redacted_line')->all(),
        DB::table('agent_prerequisites')->pluck('path_hint')->all(),
    ], JSON_THROW_ON_ERROR);
    expect($persisted)->not->toContain('query-value', 'credential', 'cookie', 'configured-agent-secret', 'operator@example.test', '192.0.2.44');
})->group('AC-agent-v2-expanded-monitors-3');

it('defaults server settings and applies only positive project-isolated caps to the next evaluation', function (): void {
    Queue::fake();
    $project = (string) Str::uuid();
    $otherProject = (string) Str::uuid();
    $server = RegisteredServer::register($project);
    $other = RegisteredServer::register($otherProject);

    expect($server->link_cap_bps)->toBe(1_000_000_000)
        ->and($server->share_redacted_logs)->toBeFalse()
        ->and($other->link_cap_bps)->toBe(1_000_000_000)
        ->and($other->share_redacted_logs)->toBeFalse();

    $override = app(OverrideServerLinkCap::class);
    expect(fn () => $override->execute($project, $server->server_uuid, 0))->toThrow(ValidationException::class)
        ->and(fn () => $override->execute($project, $server->server_uuid, -1))->toThrow(ValidationException::class)
        ->and(fn () => $override->execute($project, $other->server_uuid, 2_000_000_000))->toThrow(ValidationException::class);

    $override->execute($project, $server->server_uuid, 2_500_000_000);
    expect($server->refresh()->link_cap_bps)->toBe(2_500_000_000)
        ->and($other->refresh()->link_cap_bps)->toBe(1_000_000_000);

    $token = ProjectApiToken::issue($project, 'agent', ['agent:report']);
    $payload = validAgentReportPayload($server->server_uuid);
    $this->withToken($token->plainTextToken())->postJson('/api/v2/agent-reports', $payload)->assertAccepted();
    Queue::assertPushed(EvaluateAgentReport::class, function (EvaluateAgentReport $job): bool {
        $job->handle(app(PrepareAgentReportEvaluation::class));

        return true;
    });

    expect(AgentReport::query()->where('operation_id', $payload['operation_id'])->value('evaluation_link_cap_bps'))
        ->toBe(2_500_000_000);
})->group('AC-agent-v2-expanded-monitors-4');
