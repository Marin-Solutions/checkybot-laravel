<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Actions\IngestMonitorResult;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\NormalizedMonitorResult;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\NormalizedThresholds;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\IncidentGroupMember;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\NotificationIntent;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Actions\CreateMaintenanceMode;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Data\CreateMaintenanceData;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Jobs\ProcessMaintenanceCatchUp;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Models\MaintenanceMode;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorIdentity;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorType;
use MarinSolutions\CheckybotLaravel\Models\MonitorState;
use MarinSolutions\CheckybotLaravel\Models\MonitorTransition;
use MarinSolutions\CheckybotLaravel\Models\OutboxEvent;
use Symfony\Component\Process\Process;

function maintenanceQueueWorker(string $database): Process
{
    $root = dirname(__DIR__, 3);
    $process = new Process([
        PHP_BINARY,
        dirname(__DIR__).'/MonitoringFoundation/Support/queue-worker.php',
        $root,
        $database,
    ], $root, [
        'APP_ENV' => 'testing',
        'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $database,
        'QUEUE_CONNECTION' => 'database',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
    ]);
    $process->setTimeout(45);
    $process->run();

    return $process;
}

function maintenanceQueuedMode(string $scope, ?string $projectId, int $minutes = 60): MaintenanceMode
{
    return app(CreateMaintenanceMode::class)->execute(new CreateMaintenanceData(
        (string) Str::uuid(),
        $scope,
        $projectId,
        $minutes,
        'runtime proof',
    ))->mode;
}

function maintenanceQueuedState(string $projectId, string $monitorId, string $state, string $type = 'website'): void
{
    MonitorState::query()->create([
        'project_id' => $projectId,
        'monitor_id' => $monitorId,
        'monitor_type' => $type,
        'state' => $state,
        'severity' => $state === 'down' ? 'critical' : 'warn',
        'observed_at' => now(),
        'entered_at' => now(),
    ]);
}

function maintenanceQueuedPush(string $projectId, string $monitorId, float $value, CarbonImmutable $observedAt): NormalizedMonitorResult
{
    return new NormalizedMonitorResult(
        (string) Str::uuid(),
        new MonitorIdentity($projectId, $monitorId, MonitorType::Server),
        'push',
        $observedAt,
        $value >= 80 ? 'critical' : ($value >= 50 ? 'warn' : 'healthy'),
        'metric-band',
        $value,
        new NormalizedThresholds(50, 80),
    );
}

beforeEach(function (): void {
    $directory = dirname(__DIR__, 3).'/build/maintenance-queue-runtime-tests';
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    $this->maintenanceQueueDatabase = $directory.'/'.Str::uuid().'.sqlite';
    touch($this->maintenanceQueueDatabase);

    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => $this->maintenanceQueueDatabase,
        'prefix' => '',
        'foreign_key_constraints' => true,
        'busy_timeout' => 15000,
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
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_010200_create_maintenance_mode_tables.php')->up();
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
    @unlink($this->maintenanceQueueDatabase);
});

it('persists suppressed transitions through registered jobs and a real queue worker', function (): void {
    $project = (string) Str::uuid();
    $monitor = (string) Str::uuid();
    maintenanceQueuedMode('project', $project);
    $base = CarbonImmutable::instance(now());

    foreach ([90, 90, 90, 40, 40, 40] as $index => $value) {
        app(IngestMonitorResult::class)->ingest(maintenanceQueuedPush($project, $monitor, $value, $base->addSeconds($index)));
    }
    expect(DB::table('jobs')->count())->toBe(6);

    $worker = maintenanceQueueWorker($this->maintenanceQueueDatabase);
    expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput()."\n".$worker->getOutput());
    $transitions = MonitorTransition::query()->orderBy('operation_sequence')->get();
    expect($transitions->map->to_state->map->value->all())->toBe(['warn', 'down', 'healthy'])
        ->and($transitions->every(fn (MonitorTransition $transition): bool => $transition->maintenance_suppressed))->toBeTrue()
        ->and(NotificationIntent::query()->count())->toBe(0)
        ->and(OutboxEvent::query()->where('event_type', 'notification.intent.created')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);

    expect(Artisan::call('checkybot:foundation-relay'))->toBe(0);
    $relayWorker = maintenanceQueueWorker($this->maintenanceQueueDatabase);
    expect($relayWorker->isSuccessful())->toBeTrue($relayWorker->getErrorOutput()."\n".$relayWorker->getOutput())
        ->and(OutboxEvent::query()->where('event_type', 'monitor.transitioned')->where('status', 'delivered')->count())->toBe(3)
        ->and(NotificationIntent::query()->count())->toBe(0);
})->group('AC-alerting-reliability-core-13');

it('processes early-clear and duplicate concurrent expiry claims through real queue workers', function (): void {
    $projectA = (string) Str::uuid();
    $projectB = (string) Str::uuid();
    $downA = (string) Str::uuid();
    $warnA = (string) Str::uuid();
    maintenanceQueuedState($projectA, $downA, 'down');
    maintenanceQueuedState($projectA, $warnA, 'warn', 'api');
    maintenanceQueuedState($projectA, (string) Str::uuid(), 'healthy');
    maintenanceQueuedState($projectB, (string) Str::uuid(), 'down', 'server');
    $mode = maintenanceQueuedMode('global', null);

    $this->actingAs(new GenericUser(['id' => 1, 'is_admin' => true]))
        ->deleteJson('/api/v1/maintenance-modes/'.$mode->public_id)
        ->assertNoContent();
    $earlyWorker = maintenanceQueueWorker($this->maintenanceQueueDatabase);
    expect($earlyWorker->isSuccessful())->toBeTrue($earlyWorker->getErrorOutput()."\n".$earlyWorker->getOutput())
        ->and($mode->fresh()->catch_up_claimed_at)->not->toBeNull()
        ->and(NotificationIntent::query()->where('phase', 'incident')->count())->toBe(2)
        ->and(IncidentGroupMember::query()->where('project_id', $projectA)->count())->toBe(2)
        ->and(NotificationIntent::query()->where('project_id', $projectA)->sole()->payload['problem_filter']['monitor_uuids'])->toBe([$downA, $warnA]);

    $raceProject = (string) Str::uuid();
    maintenanceQueuedState($raceProject, (string) Str::uuid(), 'down');
    $raceMode = maintenanceQueuedMode('project', $raceProject);
    $raceMode->forceFill(['ends_at' => now()->subMinute()])->save();
    $root = dirname(__DIR__, 3);
    $barrier = $root.'/build/maintenance-queue-runtime-tests/barrier-'.Str::uuid();
    $readyFiles = [$barrier.'-ready-0', $barrier.'-ready-1'];
    ProcessMaintenanceCatchUp::dispatch($raceMode->public_id, $barrier, $readyFiles[0])->onQueue('race-0');
    ProcessMaintenanceCatchUp::dispatch($raceMode->public_id, $barrier, $readyFiles[1])->onQueue('race-1');
    DB::table('jobs')->update(['available_at' => time() - 60]);
    expect(DB::table('jobs')->orderBy('id')->pluck('queue')->all())->toBe(['race-0', 'race-1']);

    $processes = [];
    for ($index = 0; $index < 2; $index++) {
        $process = new Process([
            PHP_BINARY,
            __DIR__.'/Support/catch-up-racer.php',
            $root,
            $this->maintenanceQueueDatabase,
            $barrier,
            $readyFiles[$index],
            (string) $index,
        ], $root);
        $process->setTimeout(45);
        $process->start();
        $processes[] = $process;

        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            clearstatcache(true, $readyFiles[$index]);
            if (is_file($readyFiles[$index])) {
                break;
            }
            usleep(20_000);
        }
    }
    clearstatcache();
    $readyCount = count(array_filter($readyFiles, 'is_file'));
    touch($barrier);
    $workerOutput = '';
    foreach ($processes as $process) {
        $process->wait();
        $workerOutput .= $process->getErrorOutput()."\n".$process->getOutput();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput()."\n".$process->getOutput());
    }
    expect($readyCount)->toBe(2, $workerOutput);
    foreach ([$barrier, ...$readyFiles] as $file) {
        @unlink($file);
    }

    expect($raceMode->fresh()->catch_up_claimed_at)->not->toBeNull()
        ->and(NotificationIntent::query()->where('project_id', $raceProject)->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);

    expect(Artisan::call('checkybot:foundation-relay'))->toBe(0);
    $relayWorker = maintenanceQueueWorker($this->maintenanceQueueDatabase);
    expect($relayWorker->isSuccessful())->toBeTrue($relayWorker->getErrorOutput()."\n".$relayWorker->getOutput())
        ->and(OutboxEvent::query()->where('event_type', 'notification.intent.created')->where('status', 'delivered')->count())->toBe(3);
})->group('AC-alerting-reliability-core-14');

it('leaves overdue work queued while the worker is unavailable then catches up without processing active maintenance', function (): void {
    $expiredProject = (string) Str::uuid();
    $activeProject = (string) Str::uuid();
    maintenanceQueuedState($expiredProject, (string) Str::uuid(), 'down');
    maintenanceQueuedState($activeProject, (string) Str::uuid(), 'down');
    $expired = maintenanceQueuedMode('project', $expiredProject);
    $expired->forceFill([
        'starts_at' => now()->subMinutes(2),
        'ends_at' => now()->subMinute(),
    ])->save();
    $active = maintenanceQueuedMode('project', $activeProject);

    expect(Artisan::call('checkybot:maintenance-expire'))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and($expired->fresh()->catch_up_queued_at)->not->toBeNull()
        ->and($expired->fresh()->catch_up_claimed_at)->toBeNull()
        ->and($active->fresh()->catch_up_queued_at)->toBeNull();

    $worker = maintenanceQueueWorker($this->maintenanceQueueDatabase);
    expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput()."\n".$worker->getOutput())
        ->and($expired->fresh()->catch_up_claimed_at)->not->toBeNull()
        ->and($active->fresh()->catch_up_claimed_at)->toBeNull()
        ->and(NotificationIntent::query()->where('project_id', $expiredProject)->count())->toBe(1)
        ->and(NotificationIntent::query()->where('project_id', $activeProject)->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);

    expect(Artisan::call('checkybot:foundation-relay'))->toBe(0);
    $relayWorker = maintenanceQueueWorker($this->maintenanceQueueDatabase);
    expect($relayWorker->isSuccessful())->toBeTrue($relayWorker->getErrorOutput()."\n".$relayWorker->getOutput())
        ->and(OutboxEvent::query()->where('event_type', 'notification.intent.created')->where('status', 'delivered')->count())->toBe(1);
})->group('AC-alerting-reliability-core-15');
