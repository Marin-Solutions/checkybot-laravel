<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function runWebTimelineWorker(string $database): Process
{
    $root = dirname(__DIR__, 3);
    $worker = new Process([
        PHP_BINARY,
        dirname(__DIR__).'/MonitoringFoundation/Support/queue-worker.php',
        $root,
        $database,
    ], $root);
    $worker->setTimeout(30);
    $worker->run();

    return $worker;
}

beforeEach(function (): void {
    $directory = dirname(__DIR__, 3).'/build/web-dashboard-seam-tests';
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    $this->webTimelineDatabase = $directory.'/'.Str::uuid().'.sqlite';
    touch($this->webTimelineDatabase);

    config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => $this->webTimelineDatabase,
        'prefix' => '',
        'foreign_key_constraints' => true,
        'busy_timeout' => 10000,
    ]);
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'connection' => 'sqlite',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
        'after_commit' => true,
    ]);
    DB::purge('sqlite');
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_000000_create_monitor_foundation_tables.php')->up();
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_010000_create_alerting_result_runtime_tables.php')->up();
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_010100_create_alerting_incident_group_tables.php')->up();
    Schema::create('jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
});

afterEach(function (): void {
    DB::disconnect('sqlite');
    @unlink($this->webTimelineDatabase);
});

it('crosses real alerting HTTP relay queue and authenticated Inertia timeline boundaries', function (): void {
    $project = (string) Str::uuid();
    $monitor = (string) Str::uuid();

    $this->getJson('/__harness/web-dashboard/authenticate/'.$project)
        ->assertOk()
        ->assertJsonPath('authenticated', true)
        ->assertJsonPath('project_uuid', $project);

    foreach ([0, 1, 2] as $offset) {
        $this->postJson('/__harness/alerting/results', [
            'operation_id' => (string) Str::uuid(),
            'identity' => [
                'project_uuid' => $project,
                'monitor_uuid' => $monitor,
                'type' => 'server',
            ],
            'source' => 'push',
            'observed_at' => now()->subSeconds(3 - $offset)->toRfc3339String(),
            'signal' => 'critical',
            'reason_code' => 'cpu-critical',
            'value' => 90,
            'thresholds' => ['warn' => 50, 'critical' => 80, 'recovery_delta' => 5],
        ])->assertAccepted()->assertJsonPath('status', 'queued');
    }

    $resultWorker = runWebTimelineWorker($this->webTimelineDatabase);
    expect($resultWorker->isSuccessful())->toBeTrue($resultWorker->getErrorOutput()."\n".$resultWorker->getOutput());

    expect(Artisan::call('checkybot:foundation-relay'))->toBe(0);
    $relayWorker = runWebTimelineWorker($this->webTimelineDatabase);
    expect($relayWorker->isSuccessful())->toBeTrue($relayWorker->getErrorOutput()."\n".$relayWorker->getOutput());

    $response = $this->withHeaders(['X-Inertia' => 'true', 'Accept' => 'application/json'])
        ->get('/checkybot/monitors/server/'.$monitor)
        ->assertOk()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'CheckybotDashboard/MonitorDetail')
        ->assertJsonPath('props.monitor.identity.project_id', $project)
        ->assertJsonPath('props.monitor.identity.monitor_id', $monitor)
        ->assertJsonPath('props.monitor.current_state', 'down');

    expect(array_column($response->json('props.timeline.transitions'), 'to'))->toBe(['warn', 'down'])
        ->and($response->json('props.timeline.transitions.1.severity'))->toBe('critical')
        ->and($response->json('props.timeline.transitions.1.group_id'))->toBeString()
        ->and($response->json('props.timeline.incident_groups'))->toHaveCount(1)
        ->and($response->json('props.timeline.incident_groups.0.affected_monitors.0.monitor_uuid'))->toBe($monitor)
        ->and(DB::table('outbox_events')->where('status', 'delivered')->count())->toBe(2);
})->group('AC-web-dashboard-api-builder-3');
