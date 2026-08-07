<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Actions\ProcessAcceptedMonitorResult;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\MonitorResultIngestionInterface;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\NormalizedMonitorResult;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\NormalizedThresholds;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\PullRecheckProducer;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Jobs\ProcessMonitorResult;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Jobs\RequestPullRecheck;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\AlertingResult;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\PullRetryRequest;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Support\DeterministicPullRecheckProducer;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorIdentity;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorType;
use MarinSolutions\CheckybotLaravel\Models\MonitorState;
use MarinSolutions\CheckybotLaravel\Models\MonitorTransition;
use MarinSolutions\CheckybotLaravel\Models\OutboxEvent;
use Symfony\Component\Process\Process;

function alertingPull(string $project, string $monitor, string $signal, CarbonImmutable $at, ?string $operation = null): NormalizedMonitorResult
{
    return new NormalizedMonitorResult(
        $operation ?? (string) Str::uuid(),
        new MonitorIdentity($project, $monitor, MonitorType::Website),
        'pull',
        $at,
        $signal,
        $signal === 'failure' ? 'timeout' : null,
    );
}

function alertingPush(string $project, string $monitor, float $value, CarbonImmutable $at): NormalizedMonitorResult
{
    $thresholds = new NormalizedThresholds(50, 80);
    $signal = $value >= 80 ? 'critical' : ($value >= 50 ? 'warn' : 'healthy');

    return new NormalizedMonitorResult(
        (string) Str::uuid(),
        new MonitorIdentity($project, $monitor, MonitorType::Server),
        'push',
        $at,
        $signal,
        'metric-band',
        $value,
        $thresholds,
    );
}

function processAlertingResult(NormalizedMonitorResult $result): void
{
    app(MonitorResultIngestionInterface::class)->ingest($result);
    app(ProcessAcceptedMonitorResult::class)->execute($result->operationId);
}

/** @param array<string, string> $environment */
function runAlertingWorker(string $database, array $environment = []): Process
{
    $root = dirname(__DIR__, 3);
    $process = new Process([
        PHP_BINARY,
        dirname(__DIR__).'/MonitoringFoundation/Support/queue-worker.php',
        $root,
        $database,
    ], $root, array_merge([
        'APP_ENV' => 'testing',
        'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $database,
        'QUEUE_CONNECTION' => 'database',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
    ], $environment));
    $process->setTimeout(30);
    $process->run();

    return $process;
}

beforeEach(function (): void {
    $directory = dirname(__DIR__, 3).'/build/alerting-runtime-tests';
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    $this->alertingDatabase = $directory.'/'.Str::uuid().'.sqlite';
    touch($this->alertingDatabase);

    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => $this->alertingDatabase,
        'prefix' => '',
        'foreign_key_constraints' => true,
        'busy_timeout' => 10000,
    ]);
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', [
        'driver' => 'database', 'connection' => 'sqlite', 'table' => 'jobs',
        'queue' => 'default', 'retry_after' => 90, 'after_commit' => true,
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
    CarbonImmutable::setTestNow();
    DB::disconnect('sqlite');
    @unlink($this->alertingDatabase);
});

it('validates immutable pull and push contracts and queues an operation exactly once', function (): void {
    Queue::fake();
    $project = (string) Str::uuid();
    $monitor = (string) Str::uuid();
    $at = CarbonImmutable::parse('2026-08-07T10:00:00+00:00');
    $pull = alertingPull($project, $monitor, 'failure', $at);
    $ingestion = app(MonitorResultIngestionInterface::class);

    expect($ingestion->ingest($pull)->status)->toBe('queued')
        ->and($ingestion->ingest($pull)->status)->toBe('duplicate')
        ->and(AlertingResult::query()->count())->toBe(1);
    Queue::assertPushed(ProcessMonitorResult::class, 1);

    expect(fn () => new NormalizedThresholds(NAN, 80))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new NormalizedMonitorResult((string) Str::uuid(), $pull->identity, 'pull', $at, 'failure', thresholds: new NormalizedThresholds(50, 80)))
        ->toThrow(InvalidArgumentException::class);

    $push = alertingPush($project, (string) Str::uuid(), 85, $at->addSecond());
    expect($ingestion->ingest($push)->accepted)->toBeTrue();
    Queue::assertPushed(ProcessMonitorResult::class, 2);

    $changed = alertingPull($project, $monitor, 'success', $at, $pull->operationId);
    expect(fn () => $ingestion->ingest($changed))->toThrow(ValidationException::class);
})->group('AC-alerting-reliability-core-1');

it('requests actual pull rechecks at ten and thirty seconds and alarms only on the third failed attempt', function (): void {
    Queue::fake();
    $project = (string) Str::uuid();
    $monitor = (string) Str::uuid();
    $start = CarbonImmutable::parse('2026-08-07T10:00:00+00:00');
    CarbonImmutable::setTestNow($start);

    processAlertingResult(alertingPull($project, $monitor, 'failure', $start));
    $firstRetry = PullRetryRequest::query()->firstOrFail();
    expect(MonitorState::query()->firstOrFail()->state->value)->toBe('warn')
        ->and(MonitorTransition::query()->get()->map->to_state->map->value->all())->toBe(['warn'])
        ->and($firstRetry->due_at->equalTo($start->addSeconds(10)))->toBeTrue();

    CarbonImmutable::setTestNow($start->addSeconds(9));
    Artisan::call('checkybot:alerting-retries');
    Queue::assertNotPushed(RequestPullRecheck::class);
    CarbonImmutable::setTestNow($start->addSeconds(10));
    Artisan::call('checkybot:alerting-retries');
    Queue::assertPushed(RequestPullRecheck::class, 1);
    (new RequestPullRecheck($firstRetry->public_id))->handle(app(PullRecheckProducer::class));
    expect($firstRetry->fresh()->requested_at)->not->toBeNull()
        ->and(app(DeterministicPullRecheckProducer::class)->requests())->toHaveCount(1);

    processAlertingResult(alertingPull($project, $monitor, 'failure', $start->addSeconds(10)));
    expect(PullRetryRequest::query()->orderBy('due_at')->get())->toHaveCount(2)
        ->and(PullRetryRequest::query()->orderByDesc('due_at')->firstOrFail()->due_at->equalTo($start->addSeconds(30)))->toBeTrue()
        ->and(MonitorTransition::query()->count())->toBe(1);

    processAlertingResult(alertingPull($project, $monitor, 'failure', $start->addSeconds(30)));
    expect(MonitorState::query()->firstOrFail()->state->value)->toBe('down')
        ->and(MonitorTransition::query()->orderBy('operation_sequence')->get()->map->to_state->map->value->all())->toBe(['warn', 'down'])
        ->and(OutboxEvent::query()->where('event_type', 'notification.intent.created')->count())->toBe(0);
})->group('AC-alerting-reliability-core-2');

it('returns a pre-alarm pull recovery to healthy without any notification intent', function (): void {
    Queue::fake();
    $start = CarbonImmutable::parse('2026-08-07T10:00:00+00:00');
    $project = (string) Str::uuid();
    $monitor = (string) Str::uuid();
    processAlertingResult(alertingPull($project, $monitor, 'failure', $start));
    processAlertingResult(alertingPull($project, $monitor, 'failure', $start->addSeconds(10)));
    processAlertingResult(alertingPull($project, $monitor, 'success', $start->addSeconds(20)));

    expect(MonitorState::query()->firstOrFail()->state->value)->toBe('healthy')
        ->and(MonitorTransition::query()->orderBy('operation_sequence')->get()->map->to_state->map->value->all())->toBe(['warn', 'healthy'])
        ->and(OutboxEvent::query()->where('event_type', 'notification.intent.created')->count())->toBe(0)
        ->and(PullRetryRequest::query()->whereNotNull('canceled_at')->count())->toBe(2);

    CarbonImmutable::setTestNow($start->addSeconds(30));
    Artisan::call('checkybot:alerting-retries');
    Queue::assertNotPushed(RequestPullRecheck::class);
})->group('AC-alerting-reliability-core-2');

it('applies three-sample push confirmation recovery and interrupt resets without threshold churn', function (): void {
    Queue::fake();
    $project = (string) Str::uuid();
    $start = CarbonImmutable::parse('2026-08-07T11:00:00+00:00');

    $cases = [
        'warn-confirmation' => [[60, 60, 60], 'warn', 1],
        'critical-confirmation' => [[90, 90, 90], 'down', 2],
        'interrupted-warn' => [[60, 40, 60, 60], 'healthy', 0],
        'threshold-oscillation' => [[50, 49, 51, 49, 50, 49], 'healthy', 0],
    ];
    foreach ($cases as [$values, $expectedState, $expectedTransitions]) {
        $monitor = (string) Str::uuid();
        foreach ($values as $index => $value) {
            processAlertingResult(alertingPush($project, $monitor, $value, $start->addSeconds($index)));
        }
        expect(MonitorState::query()->where('monitor_id', $monitor)->firstOrFail()->state->value)->toBe($expectedState)
            ->and(MonitorTransition::query()->where('monitor_id', $monitor)->count())->toBe($expectedTransitions);
    }

    $recoveryMonitor = (string) Str::uuid();
    foreach ([60, 60, 60, 45, 45] as $index => $value) {
        processAlertingResult(alertingPush($project, $recoveryMonitor, $value, $start->addMinutes(1)->addSeconds($index)));
    }
    expect(MonitorState::query()->where('monitor_id', $recoveryMonitor)->firstOrFail()->state->value)->toBe('warn');
    processAlertingResult(alertingPush($project, $recoveryMonitor, 45, $start->addMinutes(1)->addSeconds(5)));
    expect(MonitorState::query()->where('monitor_id', $recoveryMonitor)->firstOrFail()->state->value)->toBe('healthy')
        ->and(MonitorTransition::query()->where('monitor_id', $recoveryMonitor)->get()->map->to_state->map->value->all())->toBe(['warn', 'healthy']);
})->group('AC-alerting-reliability-core-3');

it('persists one project-isolated state with ordered immutable reasoned transitions and an outbox', function (): void {
    Queue::fake();
    $monitor = (string) Str::uuid();
    $at = CarbonImmutable::parse('2026-08-07T11:30:00+00:00');
    $projects = [(string) Str::uuid(), (string) Str::uuid()];
    foreach ($projects as $project) {
        processAlertingResult(alertingPull($project, $monitor, 'failure', $at));
    }

    expect(MonitorState::query()->count())->toBe(2)
        ->and(MonitorState::query()->where('monitor_id', $monitor)->distinct()->count('project_id'))->toBe(2)
        ->and(MonitorTransition::query()->count())->toBe(2)
        ->and(OutboxEvent::query()->count())->toBe(2);
    foreach ($projects as $project) {
        $transition = MonitorTransition::query()->where('project_id', $project)->firstOrFail();
        expect($transition->operation_sequence)->toBe(1)
            ->and($transition->reason_code)->toBe('timeout')
            ->and($transition->entered_at->equalTo($at))->toBeTrue();
    }
    expect(function (): void {
        $transition = MonitorTransition::query()->firstOrFail();
        $transition->forceFill(['reason_code' => 'changed'])->save();
    })->toThrow(LogicException::class, 'immutable');
})->group('AC-alerting-reliability-core-4');

it('rolls current state transition and outbox back atomically', function (): void {
    Queue::fake();
    $operation = (string) Str::uuid();
    $result = alertingPull((string) Str::uuid(), (string) Str::uuid(), 'failure', CarbonImmutable::parse('2026-08-07T12:00:00+00:00'), $operation);
    app(MonitorResultIngestionInterface::class)->ingest($result);
    DB::statement("CREATE TRIGGER reject_alerting_outbox BEFORE INSERT ON outbox_events WHEN NEW.operation_id = '{$operation}' BEGIN SELECT RAISE(ABORT, 'outbox unavailable'); END");

    expect(fn () => app(ProcessAcceptedMonitorResult::class)->execute($operation))->toThrow(QueryException::class)
        ->and(MonitorState::query()->count())->toBe(0)
        ->and(MonitorTransition::query()->count())->toBe(0)
        ->and(OutboxEvent::query()->count())->toBe(0)
        ->and(AlertingResult::query()->firstOrFail()->status)->toBe('queued');

    (new ProcessMonitorResult($operation))->failed(new RuntimeException('terminal queue failure'));
    expect(AlertingResult::query()->firstOrFail()->status)->toBe('failed');
})->group('AC-alerting-reliability-core-4');

it('uses barrier synchronized processes to prevent duplicate transitions and observed-at regression', function (): void {
    Queue::fake();
    $project = (string) Str::uuid();
    $monitor = (string) Str::uuid();
    $start = CarbonImmutable::parse('2026-08-07T13:00:00+00:00');
    $older = alertingPull($project, $monitor, 'failure', $start);
    $newer = alertingPull($project, $monitor, 'success', $start->addSecond());
    app(MonitorResultIngestionInterface::class)->ingest($older);
    app(MonitorResultIngestionInterface::class)->ingest($newer);

    $root = dirname(__DIR__, 3);
    $barrier = $root.'/build/alerting-runtime-tests/barrier-'.Str::uuid();
    $ready = [];
    $processes = [];
    foreach ([$older->operationId, $newer->operationId, $newer->operationId] as $index => $operationId) {
        $ready[$index] = $barrier."-ready-{$index}";
        $process = new Process([PHP_BINARY, __DIR__.'/Support/result-racer.php', $root, $this->alertingDatabase, $barrier, $ready[$index], $operationId], $root);
        $process->setTimeout(30);
        $process->start();
        $processes[] = $process;
    }
    $deadline = microtime(true) + 10;
    while (count(array_filter($ready, 'is_file')) !== 3 && microtime(true) < $deadline) {
        usleep(20_000);
    }
    expect(count(array_filter($ready, 'is_file')))->toBe(3);
    touch($barrier);
    foreach ($processes as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
    }
    foreach ([$barrier, ...$ready] as $file) {
        @unlink($file);
    }

    $state = MonitorState::query()->firstOrFail();
    expect($state->state->value)->toBe('healthy')
        ->and($state->observed_at->equalTo($start->addSecond()))->toBeTrue()
        ->and(MonitorTransition::query()->pluck('operation_id')->unique()->count())->toBe(MonitorTransition::query()->count())
        ->and(MonitorTransition::query()->count())->toBeLessThanOrEqual(2)
        ->and(MonitorState::query()->where('project_id', $project)->count())->toBe(1);
})->group('AC-alerting-reliability-core-4');

it('proves the HTTP result seam through the real result worker foundation relay and delivery worker', function (): void {
    $project = (string) Str::uuid();
    $monitor = (string) Str::uuid();
    $operation = (string) Str::uuid();
    $payload = alertingPull($project, $monitor, 'failure', CarbonImmutable::parse('2026-08-07T14:00:00+00:00'), $operation)->toArray();

    $this->postJson('/__harness/alerting/results', $payload)
        ->assertAccepted()->assertJsonPath('status', 'queued')->assertJsonPath('operation_id', $operation);
    $resultWorker = runAlertingWorker($this->alertingDatabase);
    expect($resultWorker->isSuccessful())->toBeTrue($resultWorker->getErrorOutput());
    expect(Artisan::call('checkybot:foundation-relay'))->toBe(0);
    $relayWorker = runAlertingWorker($this->alertingDatabase);
    expect($relayWorker->isSuccessful())->toBeTrue($relayWorker->getErrorOutput());

    $this->getJson("/__harness/alerting/receipts/{$operation}")
        ->assertOk()
        ->assertJsonPath('status', 'processed')
        ->assertJsonPath('current_state', 'warn')
        ->assertJsonPath('transitions.0.to', 'warn')
        ->assertJsonFragment(['consumer' => 'agent-v2-expanded-monitors', 'effect' => 'transition_persisted']);
})->group('AC-alerting-reliability-core-5');
