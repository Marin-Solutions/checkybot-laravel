<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\GenericUser;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\AuthorizedMonitorIdentity;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\MonitorResultIngestionInterface;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\NormalizedMonitorResult;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\NormalizedThresholds;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\IncidentGroup;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\NotificationIntent;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Queries\IncidentTimelineReadModel;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Actions\ClearMaintenanceMode;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Actions\CreateMaintenanceMode;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Console\MaintenanceCommand;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Data\CreateMaintenanceData;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Jobs\ProcessMaintenanceCatchUp;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Models\MaintenanceMode;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Support\MaintenanceSilencer;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorIdentity;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorType;
use MarinSolutions\CheckybotLaravel\Http\Controllers\MaintenanceModeController;
use MarinSolutions\CheckybotLaravel\Models\MonitorState;
use MarinSolutions\CheckybotLaravel\Models\MonitorTransition;
use MarinSolutions\CheckybotLaravel\Models\OutboxEvent;
use MarinSolutions\CheckybotLaravel\Models\ProjectApiToken;
use Symfony\Component\Process\Process;

function maintenanceModeData(string $scope, ?string $projectId, int $minutes = 60, ?string $operationId = null): CreateMaintenanceData
{
    return new CreateMaintenanceData(
        $operationId ?? (string) Str::uuid(),
        $scope,
        $projectId,
        $minutes,
        'deploy window',
    );
}

function maintenanceCreate(string $scope, ?string $projectId, int $minutes = 60): MaintenanceMode
{
    return app(CreateMaintenanceMode::class)->execute(maintenanceModeData($scope, $projectId, $minutes))->mode;
}

function maintenanceState(string $projectId, string $monitorId, string $state, string $type = 'website'): MonitorState
{
    return MonitorState::query()->create([
        'project_id' => $projectId,
        'monitor_id' => $monitorId,
        'monitor_type' => $type,
        'state' => $state,
        'severity' => $state === 'down' ? 'critical' : 'warn',
        'observed_at' => now(),
        'entered_at' => now(),
    ]);
}

function maintenancePush(string $projectId, string $monitorId, float $value, CarbonImmutable $at): NormalizedMonitorResult
{
    return new NormalizedMonitorResult(
        (string) Str::uuid(),
        new MonitorIdentity($projectId, $monitorId, MonitorType::Server),
        'push',
        $at,
        $value >= 80 ? 'critical' : ($value >= 50 ? 'warn' : 'healthy'),
        'metric-band',
        $value,
        new NormalizedThresholds(50, 80),
    );
}

/** Run the registered database queue worker in an independent PHP process. */
function runMaintenanceWorker(string $database, CarbonInterface $clock): Process
{
    $root = dirname(__DIR__, 3);
    $process = new Process([
        PHP_BINARY,
        __DIR__.'/Support/catch-up-racer.php',
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
        'CHECKYBOT_TEST_NOW' => $clock->toRfc3339String(),
    ]);
    $process->setTimeout(30);
    $process->run();

    return $process;
}

/** @return array{0: ProjectApiToken, 1: string} */
function maintenanceToken(string $projectId, array $abilities, ?CarbonInterface $expiresAt = null): array
{
    $issued = ProjectApiToken::issue($projectId, 'deploy automation', $abilities, $expiresAt);

    return [$issued->accessToken, $issued->plainTextToken()];
}

beforeEach(function (): void {
    $directory = dirname(__DIR__, 3).'/build/maintenance-mode-tests';
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    $this->maintenanceDatabase = $directory.'/'.Str::uuid().'.sqlite';
    touch($this->maintenanceDatabase);

    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => $this->maintenanceDatabase,
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
    CarbonImmutable::setTestNow();
    DB::disconnect('sqlite');
    @unlink($this->maintenanceDatabase);
});

it('validates and authorizes idempotent project-token maintenance creation', function (): void {
    $project = (string) Str::uuid();
    [, $writeToken] = maintenanceToken($project, ['maintenance:write']);
    [, $readToken] = maintenanceToken($project, ['maintenance:read']);
    $headers = ['Authorization' => 'Bearer '.$writeToken];
    $valid = [
        'operation_id' => (string) Str::uuid(),
        'scope' => 'project',
        'duration_minutes' => 60,
        'reason' => 'release',
    ];

    $this->postJson('/api/v1/maintenance-modes', $valid)->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    $this->withHeader('Authorization', 'Bearer invalid-token')
        ->postJson('/api/v1/maintenance-modes', $valid)
        ->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);

    foreach ([
        ['field' => 'operation_id', 'payload' => [...$valid, 'operation_id' => 'not-a-uuid']],
        ['field' => 'duration_minutes', 'payload' => [...$valid, 'duration_minutes' => 0]],
        ['field' => 'duration_minutes', 'payload' => [...$valid, 'duration_minutes' => 1441]],
        ['field' => 'scope', 'payload' => [...$valid, 'scope' => 'account']],
    ] as $case) {
        $this->withHeaders($headers)->postJson('/api/v1/maintenance-modes', $case['payload'])
            ->assertUnprocessable()->assertJsonValidationErrors($case['field']);
    }

    $forbidden = ['message' => 'This token or operator cannot manage the requested maintenance scope.'];
    $this->withHeader('Authorization', 'Bearer '.$readToken)
        ->postJson('/api/v1/maintenance-modes', $valid)
        ->assertForbidden()->assertExactJson($forbidden);
    $this->withHeaders($headers)->postJson('/api/v1/maintenance-modes', [...$valid, 'scope' => 'global'])
        ->assertForbidden()->assertExactJson($forbidden);
    $this->withHeaders($headers)->postJson('/api/v1/maintenance-modes', [...$valid, 'project_uuid' => (string) Str::uuid()])
        ->assertForbidden()->assertExactJson($forbidden);

    $created = $this->withHeaders($headers)->postJson('/api/v1/maintenance-modes', $valid)
        ->assertCreated()
        ->assertJsonPath('data.scope', 'project')
        ->assertJsonPath('data.project_uuid', $project)
        ->assertJsonPath('data.active', true);
    $id = $created->json('data.id');
    $this->withHeaders($headers)->postJson('/api/v1/maintenance-modes', $valid)
        ->assertOk()->assertJsonPath('data.id', $id);
    $this->withHeaders($headers)->postJson('/api/v1/maintenance-modes', [...$valid, 'operation_id' => (string) Str::uuid()])
        ->assertConflict()
        ->assertExactJson(['message' => 'An active maintenance mode already exists for this scope.']);

    expect(MaintenanceMode::query()->count())->toBe(1);
})->group('AC-alerting-reliability-core-11');

it('shares the create action across API and CLI and enforces scoped reads silencing and clears', function (): void {
    $projectA = (string) Str::uuid();
    $projectB = (string) Str::uuid();
    [, $tokenA] = maintenanceToken($projectA, ['maintenance:read', 'maintenance:write']);
    [, $tokenB] = maintenanceToken($projectB, ['maintenance:read', 'maintenance:write']);
    $headersA = ['Authorization' => 'Bearer '.$tokenA];
    $projectPayload = [
        'operation_id' => (string) Str::uuid(),
        'scope' => 'project',
        'duration_minutes' => 90,
    ];
    $projectModeId = $this->withHeaders($headersA)->postJson('/api/v1/maintenance-modes', $projectPayload)
        ->assertCreated()->json('data.id');

    expect(app(MaintenanceSilencer::class)->isSilencedNow($projectA))->toBeTrue()
        ->and(app(MaintenanceSilencer::class)->isSilencedNow($projectB))->toBeFalse();

    expect(Artisan::call('checkybot:maintenance', [
        'scope' => 'global',
        '--duration' => 30,
        '--operation-id' => (string) Str::uuid(),
    ]))->toBe(0);
    $global = MaintenanceMode::query()->where('scope', 'global')->sole();
    expect(app(MaintenanceSilencer::class)->isSilencedNow($projectA))->toBeTrue()
        ->and(app(MaintenanceSilencer::class)->isSilencedNow($projectB))->toBeTrue();

    $this->withHeaders($headersA)->getJson('/api/v1/maintenance-modes/current')
        ->assertOk()
        ->assertJsonPath('data.silenced', true)
        ->assertJsonPath('data.effective_scope', 'global')
        ->assertJsonPath('data.maintenance_mode_id', $global->public_id)
        ->assertJsonPath('data.ends_at', $global->ends_at->toRfc3339String());
    $this->withHeaders($headersA)->deleteJson('/api/v1/maintenance-modes/'.$global->public_id)->assertForbidden();

    $operator = new GenericUser(['id' => 1, 'is_admin' => true]);
    $this->actingAs($operator)->withHeader('Authorization', '')
        ->deleteJson('/api/v1/maintenance-modes/'.$global->public_id)->assertNoContent();
    $this->withHeaders($headersA)->getJson('/api/v1/maintenance-modes/current')
        ->assertOk()->assertJsonPath('data.effective_scope', 'project');

    $this->withHeader('Authorization', 'Bearer '.$tokenB)
        ->deleteJson('/api/v1/maintenance-modes/'.$projectModeId)->assertForbidden();
    $this->withHeaders($headersA)->deleteJson('/api/v1/maintenance-modes/'.$projectModeId)->assertNoContent();
    expect(app(MaintenanceSilencer::class)->isSilencedNow($projectA))->toBeFalse();

    $controllerAction = (new ReflectionClass(MaintenanceModeController::class))->getConstructor()?->getParameters()[0]->getType()?->getName();
    $commandAction = (new ReflectionMethod(MaintenanceCommand::class, 'handle'))->getParameters()[0]->getType()?->getName();
    expect($controllerAction)->toBe(CreateMaintenanceMode::class)
        ->and($commandAction)->toBe(CreateMaintenanceMode::class)
        ->and(DB::table('jobs')->count())->toBe(2);
})->group('AC-alerting-reliability-core-12');

it('persists suppressed transitions and timeline flags while producing no maintenance notifications', function (): void {
    $start = CarbonImmutable::parse('2026-08-07T10:00:00+00:00');
    CarbonImmutable::setTestNow($start);
    $project = (string) Str::uuid();
    $monitor = (string) Str::uuid();
    maintenanceCreate('project', $project, 30);

    foreach ([90, 90, 90, 40, 40, 40] as $index => $value) {
        app(MonitorResultIngestionInterface::class)->ingest(
            maintenancePush($project, $monitor, $value, $start->addSeconds($index)),
        );
    }
    $worker = runMaintenanceWorker($this->maintenanceDatabase, $start);
    expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput()."\n".$worker->getOutput());

    $transitions = MonitorTransition::query()->orderBy('operation_sequence')->get();
    $timeline = app(IncidentTimelineReadModel::class)->forMonitor(new AuthorizedMonitorIdentity(
        new MonitorIdentity($project, $monitor, MonitorType::Server),
        $project,
    ));
    expect(app(MaintenanceSilencer::class)->isSilencedNow($project))->toBeTrue()
        ->and($transitions->map->to_state->map->value->all())->toBe(['warn', 'down', 'healthy'])
        ->and($transitions->every(fn (MonitorTransition $transition): bool => $transition->maintenance_suppressed))->toBeTrue()
        ->and(array_column($timeline['transitions'], 'maintenance_suppressed'))->toBe([true, true, true])
        ->and(NotificationIntent::query()->count())->toBe(0)
        ->and(OutboxEvent::query()->where('event_type', 'notification.intent.created')->count())->toBe(0);
})->group('AC-alerting-reliability-core-13');

it('catches up each affected project once and ignores healthy state and duplicate jobs', function (): void {
    $start = CarbonImmutable::parse('2026-08-07T11:00:00+00:00');
    CarbonImmutable::setTestNow($start);
    $projectA = (string) Str::uuid();
    $projectB = (string) Str::uuid();
    $down = (string) Str::uuid();
    $warn = (string) Str::uuid();
    maintenanceState($projectA, $down, 'down');
    maintenanceState($projectA, $warn, 'warn', 'api');
    maintenanceState($projectA, (string) Str::uuid(), 'healthy');
    maintenanceState($projectB, (string) Str::uuid(), 'down');
    $mode = maintenanceCreate('global', null, 1);

    $expiredAt = $start->addMinutes(2);
    CarbonImmutable::setTestNow($expiredAt);
    expect(Artisan::call('checkybot:maintenance-expire'))->toBe(0);
    ProcessMaintenanceCatchUp::dispatch($mode->public_id);
    $worker = runMaintenanceWorker($this->maintenanceDatabase, $expiredAt);
    expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput()."\n".$worker->getOutput());

    expect(IncidentGroup::query()->count())->toBe(2)
        ->and(NotificationIntent::query()->where('phase', 'incident')->count())->toBe(2)
        ->and(OutboxEvent::query()->where('event_type', 'notification.intent.created')->count())->toBe(2)
        ->and(MaintenanceMode::query()->findOrFail($mode->getKey())->catch_up_claimed_at)->not->toBeNull();
    $projectIntent = NotificationIntent::query()->where('project_id', $projectA)->sole();
    expect($projectIntent->payload['problem_filter']['monitor_uuids'])->toBe([$down, $warn])
        ->and($projectIntent->payload['affected_monitors'])->toHaveCount(2);

    $healthyProject = (string) Str::uuid();
    maintenanceState($healthyProject, (string) Str::uuid(), 'healthy');
    maintenanceCreate('project', $healthyProject, 1);
    $healthyExpiredAt = $start->addMinutes(4);
    CarbonImmutable::setTestNow($healthyExpiredAt);
    expect(Artisan::call('checkybot:maintenance-expire'))->toBe(0);
    $healthyWorker = runMaintenanceWorker($this->maintenanceDatabase, $healthyExpiredAt);
    expect($healthyWorker->isSuccessful())->toBeTrue($healthyWorker->getErrorOutput()."\n".$healthyWorker->getOutput())
        ->and(NotificationIntent::query()->count())->toBe(2);
})->group('AC-alerting-reliability-core-14');

it('queues early-clear catch-up through a real queue worker', function (): void {
    $clock = CarbonImmutable::parse('2026-08-07T12:00:00+00:00');
    CarbonImmutable::setTestNow($clock);
    $project = (string) Str::uuid();
    maintenanceState($project, (string) Str::uuid(), 'down');
    $early = maintenanceCreate('project', $project, 60);
    app(ClearMaintenanceMode::class)->execute($early);
    expect(DB::table('jobs')->count())->toBe(1);
    $earlyWorker = runMaintenanceWorker($this->maintenanceDatabase, $clock);
    expect($earlyWorker->isSuccessful())->toBeTrue($earlyWorker->getErrorOutput()."\n".$earlyWorker->getOutput())
        ->and(NotificationIntent::query()->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);
})->group('AC-alerting-reliability-core-14');

it('keeps deploy tokens hashed and enforces independent abilities lifecycle and expiry scheduling', function (): void {
    $readProject = (string) Str::uuid();
    [$readModel, $readPlain] = maintenanceToken($readProject, ['maintenance:read']);
    expect($readModel->token_hash)->toBe(hash('sha256', $readPlain))
        ->and($readModel->token_hash)->not->toBe($readPlain)
        ->and(json_encode($readModel, JSON_THROW_ON_ERROR))->not->toContain($readPlain, $readModel->token_hash);
    $this->withHeader('Authorization', 'Bearer '.$readPlain)
        ->getJson('/api/v1/maintenance-modes/current')->assertOk();
    $this->withHeader('Authorization', 'Bearer '.$readPlain)->postJson('/api/v1/maintenance-modes', [
        'operation_id' => (string) Str::uuid(), 'scope' => 'project', 'duration_minutes' => 10,
    ])->assertForbidden();

    $writeProject = (string) Str::uuid();
    [, $writePlain] = maintenanceToken($writeProject, ['maintenance:write']);
    $this->withHeader('Authorization', 'Bearer '.$writePlain)->postJson('/api/v1/maintenance-modes', [
        'operation_id' => (string) Str::uuid(), 'scope' => 'project', 'duration_minutes' => 10,
    ])->assertCreated();
    $this->withHeader('Authorization', 'Bearer '.$writePlain)
        ->getJson('/api/v1/maintenance-modes/current')->assertForbidden();

    $lifecyclePayload = [
        'operation_id' => (string) Str::uuid(), 'scope' => 'project', 'duration_minutes' => 10,
    ];
    [, $expiredPlain] = maintenanceToken((string) Str::uuid(), ['maintenance:read', 'maintenance:write'], now()->subMinute());
    $this->withHeader('Authorization', 'Bearer '.$expiredPlain)
        ->getJson('/api/v1/maintenance-modes/current')->assertUnauthorized();
    $this->withHeader('Authorization', 'Bearer '.$expiredPlain)
        ->postJson('/api/v1/maintenance-modes', $lifecyclePayload)->assertUnauthorized();
    [$revoked, $revokedPlain] = maintenanceToken((string) Str::uuid(), ['maintenance:read', 'maintenance:write']);
    $revoked->revoke();
    $this->withHeader('Authorization', 'Bearer '.$revokedPlain)
        ->getJson('/api/v1/maintenance-modes/current')->assertUnauthorized();
    $this->withHeader('Authorization', 'Bearer '.$revokedPlain)
        ->postJson('/api/v1/maintenance-modes', $lifecyclePayload)->assertUnauthorized();
    [$rotating, $oldPlain] = maintenanceToken((string) Str::uuid(), ['maintenance:read', 'maintenance:write']);
    $rotated = $rotating->rotate(['maintenance:read']);
    $this->withHeader('Authorization', 'Bearer '.$oldPlain)
        ->getJson('/api/v1/maintenance-modes/current')->assertUnauthorized();
    $this->withHeader('Authorization', 'Bearer '.$oldPlain)
        ->postJson('/api/v1/maintenance-modes', $lifecyclePayload)->assertUnauthorized();
    $this->withHeader('Authorization', 'Bearer '.$rotated->plainTextToken())
        ->getJson('/api/v1/maintenance-modes/current')->assertOk();
    $this->withHeader('Authorization', 'Bearer '.$rotated->plainTextToken())
        ->postJson('/api/v1/maintenance-modes', $lifecyclePayload)->assertForbidden();

    $expiredMode = maintenanceCreate('project', (string) Str::uuid(), 60);
    $expiredMode->forceFill(['ends_at' => now()->subMinute()])->save();
    $activeMode = maintenanceCreate('project', (string) Str::uuid(), 60);
    expect(Artisan::call('checkybot:maintenance-expire'))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and($expiredMode->fresh()->catch_up_queued_at)->not->toBeNull()
        ->and($expiredMode->fresh()->catch_up_claimed_at)->toBeNull()
        ->and($activeMode->fresh()->catch_up_queued_at)->toBeNull();

    // The overdue job remains durable while no worker is available, then is caught up
    // through queue:work. The still-active record is not processed early.
    $worker = runMaintenanceWorker($this->maintenanceDatabase, CarbonImmutable::now());
    expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput()."\n".$worker->getOutput())
        ->and($expiredMode->fresh()->catch_up_claimed_at)->not->toBeNull()
        ->and($activeMode->fresh()->catch_up_claimed_at)->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(0);
    expect(Artisan::call('checkybot:maintenance-expire'))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);

    $event = collect(app(Schedule::class)->events())
        ->first(static fn ($event): bool => str_contains((string) $event->command, 'checkybot:maintenance-expire'));
    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
})->group('AC-alerting-reliability-core-15');
