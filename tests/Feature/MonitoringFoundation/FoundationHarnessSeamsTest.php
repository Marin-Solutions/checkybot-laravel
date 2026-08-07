<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Models\OutboxEvent;
use Symfony\Component\Process\Process;

function foundationTransitionPayload(string $projectId, string $monitorId): array
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

function runFoundationQueueWorker(string $database): Process
{
    $process = new Process([
        PHP_BINARY,
        dirname(__DIR__, 3).'/vendor/bin/testbench',
        'queue:work',
        'database',
        '--stop-when-empty',
        '--sleep=1',
        '--tries=1',
        '--timeout=15',
        '--no-interaction',
    ], dirname(__DIR__, 3), [
        'APP_ENV' => 'testing',
        'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $database,
        'QUEUE_CONNECTION' => 'database',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
    ]);
    $process->setTimeout(30);
    $process->run();

    return $process;
}

beforeEach(function (): void {
    $directory = dirname(__DIR__, 3).'/build/monitor-foundation-tests';
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    $this->foundationDatabase = $directory.'/'.Str::uuid().'.sqlite';
    touch($this->foundationDatabase);

    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => $this->foundationDatabase,
        'prefix' => '',
        'foreign_key_constraints' => true,
        'busy_timeout' => 5000,
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
    @unlink($this->foundationDatabase);
});

it('delivers one transition to alerting, agent, mobile, widget and web through relay and worker', function (): void {
    $operationId = (string) Str::uuid();
    $payload = foundationTransitionPayload((string) Str::uuid(), (string) Str::uuid());

    $this->postJson('/__harness/monitor-foundation/events', [
        'operation_id' => $operationId,
        'event_type' => 'monitor.transitioned',
        'payload' => $payload,
    ])->assertAccepted()->assertJson([
        'status' => 'queued',
        'event_type' => 'monitor.transitioned',
        'operation_id' => $operationId,
    ]);

    expect(Artisan::call('checkybot:foundation-relay'))->toBe(0);
    $worker = runFoundationQueueWorker($this->foundationDatabase);
    expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());

    $response = $this->getJson("/__harness/monitor-foundation/receipts/{$operationId}")
        ->assertOk()
        ->assertJsonPath('status', 'delivered');
    $receipts = collect($response->json('receipts'));

    expect($receipts->pluck('consumer')->all())->toBe(['alerting', 'agent', 'mobile', 'widget', 'web']);
    foreach ($receipts as $receipt) {
        expect($receipt['contract_version'])->toBe($payload['contract_version'])
            ->and($receipt['identity'])->toBe($payload['identity'])
            ->and($receipt['state'])->toBe($payload['to_state'])
            ->and($receipt['severity'])->toBe($payload['severity'])
            ->and($receipt['filter'])->toBe($payload['filter']);
    }
})->group('AC-domain-runtime-foundation-3', 'AC-domain-runtime-foundation-4');

it('delivers every typed check array to the sdk and rejects malformed contracts before enqueueing', function (): void {
    $operationId = (string) Str::uuid();
    $check = static fn (string $name): array => ['name' => $name, 'url' => "https://example.com/{$name}", 'interval' => '5m'];
    $payload = [
        'contract_version' => 'check-sync.v1',
        'uptime' => [$check('uptime')],
        'ssl' => [$check('ssl')],
        'api' => [$check('api')],
        'dead_links' => [$check('dead-links')],
        'open_graph' => [$check('open-graph')],
    ];

    $this->postJson('/__harness/monitor-foundation/events', [
        'operation_id' => $operationId,
        'event_type' => 'contract.check_sync.probed',
        'payload' => $payload,
    ])->assertAccepted();

    Artisan::call('checkybot:foundation-relay');
    $worker = runFoundationQueueWorker($this->foundationDatabase);
    expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());

    $this->getJson("/__harness/monitor-foundation/receipts/{$operationId}")
        ->assertOk()
        ->assertJsonPath('status', 'delivered')
        ->assertJsonPath('receipts.0.consumer', 'sdk')
        ->assertJsonPath('receipts.0.effect', 'schema-valid')
        ->assertJsonPath('receipts.0.schema_valid', true)
        ->assertJsonPath('receipts.0.check_types', ['uptime', 'ssl', 'api', 'dead_links', 'open_graph']);

    foreach ([
        [[...$payload, 'contract_version' => 'check-sync.v999'], 'contract_version'],
        [[...$payload, 'api' => [['name' => 'broken', 'url' => 'invalid', 'interval' => 'never']]], 'api.0.url'],
    ] as [$invalidPayload, $invalidKey]) {
        $this->postJson('/__harness/monitor-foundation/events', [
            'operation_id' => (string) Str::uuid(),
            'event_type' => 'contract.check_sync.probed',
            'payload' => $invalidPayload,
        ])->assertUnprocessable()->assertJsonValidationErrors($invalidKey);
    }

    expect(OutboxEvent::query()->count())->toBe(1);
})->group('AC-domain-runtime-foundation-5');
