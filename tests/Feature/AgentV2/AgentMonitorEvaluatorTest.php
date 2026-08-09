<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Actions\PrepareAgentReportEvaluation;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\AgentMonitorEvaluation;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\AgentReport;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\RegisteredServer;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\MonitorResultIngestionInterface;
use MarinSolutions\CheckybotLaravel\Tests\Feature\AgentV2\Support\RecordingAgentIngestion;
use Ramsey\Uuid\Uuid;

beforeEach(function (): void {
    config()->set('database.default', 'agent_evaluator_test');
    config()->set('database.connections.agent_evaluator_test', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]);
    DB::purge('agent_evaluator_test');
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_030000_create_agent_v2_report_runtime_tables.php')->up();
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_031000_create_agent_monitor_evaluator_tables.php')->up();
    $this->recordingIngestion = new RecordingAgentIngestion;
    app()->instance(MonitorResultIngestionInterface::class, $this->recordingIngestion);
    Queue::fake();
});

afterEach(function (): void {
    DB::disconnect('agent_evaluator_test');
});

it('persists and submits one deterministic worst-band server aggregate', function (): void {
    $project = (string) Str::uuid();
    $server = RegisteredServer::register($project);
    $reportOperation = (string) Str::uuid();
    $report = AgentReport::query()->create([
        'operation_id' => $reportOperation,
        'agent_server_id' => $server->getKey(),
        'project_id' => $project,
        'payload_hash' => str_repeat('a', 64),
        'schema_version' => 'agent-report.v2',
        'agent_version' => '2.1.0',
        'observed_at' => now(),
        'reporting_interval_seconds' => 60,
        'cpu_five_min_percent' => 10,
        'memory_used_percent' => 10,
        'nginx_window_seconds' => 300,
        'nginx_total_requests' => 100,
        'nginx_five_xx_count' => 0,
        'nginx_upstream_timeout_count' => 0,
        'payload' => [],
    ]);
    $report->disks()->create(['mount' => '/', 'used_percent' => 10, 'predicted_days_to_full' => null]);
    $report->networkInterfaces()->create([
        'interface_name' => 'eth0', 'rx_bytes_total' => 10_000, 'tx_bytes_total' => 20_000,
        'rx_delta_bytes' => 100, 'tx_delta_bytes' => 900, 'elapsed_seconds' => 10, 'sample_status' => 'ready',
    ]);
    $report->networkInterfaces()->create([
        'interface_name' => 'eth1', 'rx_bytes_total' => 100, 'tx_bytes_total' => 100,
        'rx_delta_bytes' => null, 'tx_delta_bytes' => null, 'elapsed_seconds' => null, 'sample_status' => 'baseline',
    ]);
    $report->phpFpmPools()->create(['pool' => 'www', 'active_workers' => 1, 'max_children' => 10, 'max_children_reached_5m' => 0]);
    $report->prerequisites()->createMany([
        ['kind' => 'php_fpm_status', 'path_hint' => '[REDACTED]', 'status' => 'readable'],
        ['kind' => 'nginx_access_log', 'path_hint' => '[REDACTED]', 'status' => 'readable'],
    ]);
    $server->forceFill(['link_cap_bps' => 800])->save();

    app(PrepareAgentReportEvaluation::class)->execute($reportOperation);

    $expectedOperation = Uuid::uuid5($reportOperation, 'server-aggregate-evaluation')->toString();
    $evaluation = AgentMonitorEvaluation::query()->sole();
    expect($evaluation->operation_id)->toBe($expectedOperation)
        ->and($evaluation->signal)->toBe('critical')
        ->and($evaluation->reason_code)->toBe('network_cap_critical')
        ->and($evaluation->details['components']['network']['excluded_samples'])->toBe(1)
        ->and($report->refresh()->evaluation_link_cap_bps)->toBe(800)
        ->and($this->recordingIngestion->results)->toHaveCount(1)
        ->and($this->recordingIngestion->results[0]->operationId)->toBe($expectedOperation)
        ->and($this->recordingIngestion->results[0]->identity->monitorId)->toBe($server->server_uuid)
        ->and($this->recordingIngestion->results[0]->signal)->toBe('critical');
})->group('AC-agent-v2-expanded-monitors-5');

it('registers the due evaluator command and overlap-safe minute schedule', function (): void {
    expect(Artisan::all())->toHaveKey('checkybot:agent-evaluate-due');
    $event = collect(app(Schedule::class)->events())
        ->first(static fn ($event): bool => str_contains($event->command ?? '', 'checkybot:agent-evaluate-due'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
})->group('AC-agent-v2-expanded-monitors-8');
