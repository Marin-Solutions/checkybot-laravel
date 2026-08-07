<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Actions\ScanAgentDeadMan;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\AgentMonitorEvaluation;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\AgentServerLiveness;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\RegisteredServer;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\MonitorResultIngestionInterface;
use MarinSolutions\CheckybotLaravel\Models\ProjectApiToken;
use MarinSolutions\CheckybotLaravel\Tests\Feature\AgentV2\Support\RecordingAgentIngestion;

function deadManPayload(string $serverUuid, CarbonImmutable $observedAt): array
{
    return [
        'schema_version' => 'agent-report.v2',
        'operation_id' => (string) Str::uuid(),
        'agent_version' => '2.5.0',
        'server_uuid' => $serverUuid,
        'observed_at' => $observedAt->format('Y-m-d\TH:i:s.u\Z'),
        'reporting_interval_seconds' => 60,
        'cpu' => ['five_min_percent' => 10],
        'memory' => ['used_percent' => 10],
        'disks' => [['mount' => '/', 'used_percent' => 10, 'predicted_days_to_full' => null]],
        'network_interfaces' => [[
            'name' => 'eth0', 'rx_bytes_total' => 100, 'tx_bytes_total' => 100,
            'rx_delta_bytes' => null, 'tx_delta_bytes' => null, 'elapsed_seconds' => null,
            'sample_status' => 'baseline',
        ]],
        'php_fpm_pools' => [['pool' => 'www', 'active_workers' => 1, 'max_children' => 10, 'max_children_reached_5m' => 0]],
        'nginx_window' => ['window_seconds' => 300, 'total_requests' => 0, 'five_xx_count' => 0, 'upstream_timeout_count' => 0],
        'prerequisites' => [
            ['kind' => 'php_fpm_status', 'path_hint' => '[REDACTED]', 'status' => 'not_configured'],
            ['kind' => 'nginx_access_log', 'path_hint' => '[REDACTED]', 'status' => 'not_configured'],
        ],
    ];
}

beforeEach(function (): void {
    $this->deadManStart = CarbonImmutable::parse('2026-08-07T12:00:00Z');
    CarbonImmutable::setTestNow($this->deadManStart);
    config()->set('database.default', 'agent_dead_man_test');
    config()->set('database.connections.agent_dead_man_test', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]);
    DB::purge('agent_dead_man_test');
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_000000_create_monitor_foundation_tables.php')->up();
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_030000_create_agent_v2_report_runtime_tables.php')->up();
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_031000_create_agent_monitor_evaluator_tables.php')->up();
    $this->recordingIngestion = new RecordingAgentIngestion;
    app()->instance(MonitorResultIngestionInterface::class, $this->recordingIngestion);
    Queue::fake();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    DB::disconnect('agent_dead_man_test');
});

it('uses exact dead-man boundaries, deduplicates scans, and keeps a monotonic accepted-report cursor', function (): void {
    $project = (string) Str::uuid();
    $server = RegisteredServer::register($project);
    $token = ProjectApiToken::issue($project, 'agent', ['agent:report']);

    $this->withToken($token->plainTextToken())
        ->postJson('/api/v2/agent-reports', deadManPayload($server->server_uuid, $this->deadManStart))
        ->assertAccepted();

    $scanner = app(ScanAgentDeadMan::class);
    expect($scanner->execute($this->deadManStart->addSeconds(179)))->toBe(0)
        ->and($this->recordingIngestion->results)->toHaveCount(0);

    expect($scanner->execute($this->deadManStart->addSeconds(180)))->toBe(1)
        ->and($this->recordingIngestion->results[0]->signal)->toBe('warn');
    expect($scanner->execute($this->deadManStart->addSeconds(299)))->toBe(1)
        ->and($this->recordingIngestion->results[1]->signal)->toBe('warn');
    expect($scanner->execute($this->deadManStart->addSeconds(300)))->toBe(1)
        ->and($this->recordingIngestion->results[2]->signal)->toBe('critical');

    $scanner->execute($this->deadManStart->addSeconds(300));
    expect(AgentMonitorEvaluation::query()->where('observation_kind', 'dead_man')->count())->toBe(3)
        ->and($this->recordingIngestion->results[2]->operationId)->toBe($this->recordingIngestion->results[3]->operationId)
        ->and(AgentMonitorEvaluation::query()->pluck('operation_id')->unique()->count())->toBe(3);

    CarbonImmutable::setTestNow($this->deadManStart->addSeconds(301));
    $this->withToken($token->plainTextToken())
        ->postJson('/api/v2/agent-reports', deadManPayload($server->server_uuid, $this->deadManStart->addSeconds(301)))
        ->assertAccepted();
    $this->withToken($token->plainTextToken())
        ->postJson('/api/v2/agent-reports', deadManPayload($server->server_uuid, $this->deadManStart->addSeconds(250)))
        ->assertAccepted();

    $liveness = AgentServerLiveness::query()->sole();
    expect($liveness->last_accepted_observed_at->equalTo($this->deadManStart->addSeconds(301)))->toBeTrue()
        ->and($scanner->execute($this->deadManStart->addSeconds(480)))->toBe(0)
        ->and(AgentMonitorEvaluation::query()->where('observation_kind', 'dead_man')->count())->toBe(3);
})->group('AC-agent-v2-expanded-monitors-8');
