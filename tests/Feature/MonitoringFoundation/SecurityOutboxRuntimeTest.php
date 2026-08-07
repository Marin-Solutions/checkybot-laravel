<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Console\RelayFoundationOutbox;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery\DelayedDeliveryFake;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery\DeterministicFakeEventDispatcher;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery\FoundationEventDispatcher;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery\FoundationEventProcessor;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery\RetryableFailureFake;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery\TerminalFailureFake;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Jobs\DeliverFoundationEvent;
use MarinSolutions\CheckybotLaravel\Models\MonitorState;
use MarinSolutions\CheckybotLaravel\Models\MonitorTransition;
use MarinSolutions\CheckybotLaravel\Models\OutboxEvent;
use Symfony\Component\Process\Process;

function securityOutboxTransition(string $projectId, string $monitorId): array
{
    return [
        'contract_version' => 'monitor-foundation.v1',
        'identity' => ['project_id' => $projectId, 'monitor_id' => $monitorId, 'type' => 'server'],
        'from_state' => 'healthy',
        'to_state' => 'down',
        'severity' => 'critical',
        'filter' => ['types' => ['server'], 'states' => ['down'], 'severities' => ['critical']],
        'occurred_at' => '2026-08-07T10:30:00+00:00',
    ];
}

function securityOutboxIncident(string $operationId): array
{
    return [
        'operation_id' => $operationId,
        'event_type' => 'incident.redaction.probed',
        'payload' => [
            'contract_version' => 'monitor-foundation.v1',
            'incident_id' => (string) Str::uuid(),
            'log_lines' => ['diagnostic=timeout'],
        ],
    ];
}

/** @param array<string, string> $environment */
function runSecurityOutboxQueueWorker(string $database, array $environment = []): Process
{
    $root = dirname(__DIR__, 3);
    $process = new Process([
        PHP_BINARY,
        $root.'/vendor/bin/testbench',
        'queue:work',
        'database',
        '--stop-when-empty',
        '--sleep=1',
        '--tries=1',
        '--timeout=15',
        '--no-interaction',
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
    $directory = dirname(__DIR__, 3).'/build/security-outbox-tests';
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    $this->securityOutboxDatabase = $directory.'/'.Str::uuid().'.sqlite';
    touch($this->securityOutboxDatabase);

    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => $this->securityOutboxDatabase,
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
    @unlink($this->securityOutboxDatabase);
});

it('commits a domain write and outbox atomically and treats matching operation ids as idempotent', function (): void {
    $operationId = (string) Str::uuid();
    DB::statement("CREATE TRIGGER reject_outbox BEFORE INSERT ON outbox_events WHEN NEW.operation_id = '{$operationId}' BEGIN SELECT RAISE(ABORT, 'outbox unavailable'); END");
    $projectId = (string) Str::uuid();
    $monitorId = (string) Str::uuid();
    $transition = [
        'operation_id' => $operationId,
        'event_type' => 'monitor.transitioned',
        'payload' => securityOutboxTransition($projectId, $monitorId),
    ];

    $this->withoutExceptionHandling();
    $failure = null;
    try {
        $this->postJson('/__harness/monitor-foundation/events', $transition);
    } catch (Throwable $exception) {
        $failure = $exception;
    }
    expect($failure)->toBeInstanceOf(Throwable::class);
    expect(MonitorState::query()->count())->toBe(0)
        ->and(MonitorTransition::query()->count())->toBe(0)
        ->and(OutboxEvent::query()->count())->toBe(0);

    $this->withExceptionHandling();
    $idempotentOperationId = (string) Str::uuid();
    $transition['operation_id'] = $idempotentOperationId;
    $this->postJson('/__harness/monitor-foundation/events', $transition)->assertAccepted();
    $this->postJson('/__harness/monitor-foundation/events', $transition)->assertAccepted();

    expect(MonitorState::query()->count())->toBe(1)
        ->and(MonitorTransition::query()->count())->toBe(1)
        ->and(OutboxEvent::query()->count())->toBe(1);

    app(FoundationEventProcessor::class)->process($idempotentOperationId);
    app(FoundationEventProcessor::class)->process($idempotentOperationId);
    expect(OutboxEvent::query()->first()->receipts)->toHaveCount(5);
})->group('AC-domain-runtime-foundation-9');

it('uses barrier synchronized independent relay processes so concurrent claims enqueue once', function (): void {
    $operationId = (string) Str::uuid();
    OutboxEvent::query()->create([
        'operation_id' => $operationId,
        'event_type' => 'incident.redaction.probed',
        'contract_version' => 'monitor-foundation.v1',
        'payload' => securityOutboxIncident($operationId)['payload'],
        'available_at' => now(),
    ]);

    $root = dirname(__DIR__, 3);
    $barrier = $root.'/build/security-outbox-tests/barrier-'.Str::uuid();
    $processes = [];
    $readyFiles = [];
    for ($index = 0; $index < 2; $index++) {
        $ready = $barrier."-ready-{$index}";
        $readyFiles[] = $ready;
        $process = new Process([
            PHP_BINARY,
            __DIR__.'/Support/relay-racer.php',
            $root,
            $this->securityOutboxDatabase,
            $barrier,
            $ready,
        ], $root);
        $process->setTimeout(30);
        $process->start();
        $processes[] = $process;
    }

    $deadline = microtime(true) + 10;
    while (count(array_filter($readyFiles, 'is_file')) !== 2 && microtime(true) < $deadline) {
        usleep(20_000);
    }
    expect(count(array_filter($readyFiles, 'is_file')))->toBe(2);
    touch($barrier);

    $processOutput = '';
    foreach ($processes as $process) {
        $process->wait();
        $processOutput .= $process->getOutput().$process->getErrorOutput();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput()."\n".$process->getOutput());
    }
    foreach ([$barrier, ...$readyFiles] as $file) {
        @unlink($file);
    }

    expect(DB::table('jobs')->count())->toBe(1)
        ->and(OutboxEvent::query()->where('operation_id', $operationId)->value('status'))->toBe('pending');
})->group('AC-domain-runtime-foundation-9');

it('enforces retry backoff against duplicate jobs through the real relay and queue worker', function (): void {
    $operationId = (string) Str::uuid();
    OutboxEvent::query()->create([
        'operation_id' => $operationId,
        'event_type' => 'incident.redaction.probed',
        'contract_version' => 'monitor-foundation.v1',
        'payload' => securityOutboxIncident($operationId)['payload'],
        'available_at' => now(),
    ]);

    // Simulate stopped-worker lease recovery so two messages for one operation are already queued.
    expect(Artisan::call('checkybot:foundation-relay'))->toBe(0);
    OutboxEvent::query()->where('operation_id', $operationId)->update(['claimed_at' => now()->subSeconds(61)]);
    expect(Artisan::call('checkybot:foundation-relay'))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(2);

    $retryWorker = runSecurityOutboxQueueWorker($this->securityOutboxDatabase, [
        'CHECKYBOT_FOUNDATION_DELIVERY_FAKE' => 'retryable',
        'CHECKYBOT_FOUNDATION_DELIVERY_MESSAGE' => 'configured-secret admin@example.test 192.0.2.9',
        'CHECKYBOT_REDACTION_SECRETS' => 'configured-secret',
    ]);
    expect($retryWorker->isSuccessful())->toBeTrue($retryWorker->getErrorOutput());

    $retrying = OutboxEvent::query()->where('operation_id', $operationId)->firstOrFail();
    $retryMetadata = json_encode($retrying->failure_metadata, JSON_THROW_ON_ERROR);
    expect(DB::table('jobs')->count())->toBe(0)
        ->and($retrying->status)->toBe('pending')
        ->and($retrying->attempts)->toBe(1)
        ->and($retrying->available_at->isFuture())->toBeTrue()
        ->and($retryMetadata)->not->toContain('configured-secret', 'admin@example.test', '192.0.2.9');

    // Once due, the relay creates a fresh message and a real worker records inspectable terminal metadata.
    $retrying->forceFill(['available_at' => now(), 'claimed_at' => null, 'claim_token' => null])->save();
    expect(Artisan::call('checkybot:foundation-relay'))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1);
    $terminalWorker = runSecurityOutboxQueueWorker($this->securityOutboxDatabase, [
        'CHECKYBOT_FOUNDATION_DELIVERY_FAKE' => 'terminal',
        'CHECKYBOT_FOUNDATION_DELIVERY_MESSAGE' => 'configured-secret from 2001:db8::9',
        'CHECKYBOT_REDACTION_SECRETS' => 'configured-secret',
    ]);
    expect($terminalWorker->isSuccessful())->toBeTrue($terminalWorker->getErrorOutput());

    $failed = $retrying->fresh();
    $terminalMetadata = json_encode($failed->failure_metadata, JSON_THROW_ON_ERROR);
    expect($failed->status)->toBe('failed')
        ->and($failed->attempts)->toBe(2)
        ->and($failed->failure_metadata['terminal'])->toBeTrue()
        ->and($terminalMetadata)->not->toContain('configured-secret', '2001:db8::9')
        ->and($terminalMetadata)->toContain('TerminalDeliveryException');
})->group('AC-domain-runtime-foundation-9');

it('registers relay job fakes and an overlap-safe minutely scheduler while a stopped queue leaves pending work', function (): void {
    expect(app()->bound(FoundationEventDispatcher::class))->toBeTrue()
        ->and(app()->bound(DeterministicFakeEventDispatcher::class))->toBeTrue()
        ->and(app()->bound(RetryableFailureFake::class))->toBeTrue()
        ->and(app()->bound(TerminalFailureFake::class))->toBeTrue()
        ->and(app()->bound(DelayedDeliveryFake::class))->toBeTrue()
        ->and(app()->bound(DeliverFoundationEvent::class))->toBeTrue()
        ->and(Artisan::all())->toHaveKey('checkybot:foundation-relay')
        ->and(Artisan::all()['checkybot:foundation-relay'])->toBeInstanceOf(RelayFoundationOutbox::class);

    $event = collect(app(Schedule::class)->events())
        ->first(static fn ($event): bool => str_contains((string) $event->command, 'checkybot:foundation-relay'));
    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();

    $operationId = (string) Str::uuid();
    OutboxEvent::query()->create([
        'operation_id' => $operationId,
        'event_type' => 'incident.redaction.probed',
        'contract_version' => 'monitor-foundation.v1',
        'payload' => securityOutboxIncident($operationId)['payload'],
        'available_at' => now(),
    ]);
    expect(Artisan::call('checkybot:foundation-relay'))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(OutboxEvent::query()->where('operation_id', $operationId)->value('status'))->toBe('pending');

    OutboxEvent::query()->where('operation_id', $operationId)->update(['claimed_at' => now()->subSeconds(61)]);
    expect(Artisan::call('checkybot:foundation-relay'))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(2)
        ->and(OutboxEvent::query()->where('operation_id', $operationId)->value('status'))->toBe('pending');
})->group('AC-domain-runtime-foundation-10');

it('does not register probe routes when the application environment is production', function (): void {
    $originalEnvironment = app()->environment();
    $originalRouter = app('router');
    $isolatedRouter = new Router(app('events'), app());

    try {
        app()->instance('env', 'production');
        app()->instance('router', $isolatedRouter);
        Route::swap($isolatedRouter);
        require dirname(__DIR__, 3).'/routes/status-summary.php';

        expect(collect($isolatedRouter->getRoutes()->getRoutes())
            ->pluck('uri')
            ->filter(static fn (string $uri): bool => str_starts_with($uri, '__harness/monitor-foundation'))
            ->all())->toBe([]);

        // Positive control: the same registrar exposes both routes in the canonical test runtime.
        app()->instance('env', 'testing');
        require dirname(__DIR__, 3).'/routes/status-summary.php';
        expect(collect($isolatedRouter->getRoutes()->getRoutes())->pluck('uri')->all())
            ->toContain('__harness/monitor-foundation/events', '__harness/monitor-foundation/receipts/{operationId}');
    } finally {
        app()->instance('env', $originalEnvironment);
        app()->instance('router', $originalRouter);
        Route::swap($originalRouter);
    }
})->group('AC-domain-runtime-foundation-10');
