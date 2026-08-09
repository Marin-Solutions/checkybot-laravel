<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Security\Foundation\ProjectTokenAbility;
use MarinSolutions\CheckybotLaravel\Models\ProjectApiToken;
use Symfony\Component\Process\Process;

/** @return array<string, mixed> */
function summarySeamTransition(string $projectId, string $type, string $state, string $occurredAt): array
{
    return [
        'contract_version' => 'monitor-foundation.v1',
        'identity' => ['project_id' => $projectId, 'monitor_id' => (string) Str::uuid(), 'type' => $type],
        'from_state' => 'healthy',
        'to_state' => $state,
        'severity' => $state === 'down' ? 'critical' : 'warn',
        'filter' => ['types' => [$type], 'states' => [$state], 'severities' => [$state === 'down' ? 'critical' : 'warn']],
        'occurred_at' => $occurredAt,
    ];
}

function runSummarySeamWorker(string $database): Process
{
    $root = dirname(__DIR__, 3);
    $process = new Process([
        PHP_BINARY,
        __DIR__.'/Support/queue-worker.php',
        $root,
        $database,
    ], $root);
    $process->setTimeout(30);
    $process->run();

    return $process;
}

beforeEach(function (): void {
    $directory = dirname(__DIR__, 3).'/build/status-summary-seam-tests';
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    $this->summarySeamDatabase = $directory.'/'.Str::uuid().'.sqlite';
    touch($this->summarySeamDatabase);

    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => $this->summarySeamDatabase,
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
    CarbonImmutable::setTestNow();
    DB::disconnect('sqlite');
    @unlink($this->summarySeamDatabase);
});

it('connects harness transitions through relay and worker to mobile widget receipts and authenticated summary', function (): void {
    $projectId = (string) Str::uuid();
    $token = ProjectApiToken::issue($projectId, 'mobile-widget-seam', [ProjectTokenAbility::StatusRead]);
    $operations = [];

    foreach ([
        ['server', 'down', '2026-08-07T11:57:00Z'],
        ['website', 'recovering', '2026-08-07T11:58:00Z'],
        ['api', 'healthy', '2026-08-07T11:59:00Z'],
    ] as [$type, $state, $occurredAt]) {
        $operationId = (string) Str::uuid();
        $operations[] = $operationId;
        $this->postJson('/__harness/monitor-foundation/events', [
            'operation_id' => $operationId,
            'event_type' => 'monitor.transitioned',
            'payload' => summarySeamTransition($projectId, $type, $state, $occurredAt),
        ])->assertAccepted();
    }

    DB::table('outbox_events')->update(['available_at' => now()->subMinute()]);
    expect(Artisan::call('checkybot:foundation-relay'))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(3);
    $worker = runSummarySeamWorker($this->summarySeamDatabase);
    expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput())
        ->and(DB::table('jobs')->count())->toBe(0, $worker->getOutput().$worker->getErrorOutput())
        ->and(DB::table('outbox_events')->pluck('status')->all())->toBe(['delivered', 'delivered', 'delivered']);

    foreach ($operations as $operationId) {
        $receipts = collect($this->getJson("/__harness/monitor-foundation/receipts/{$operationId}")
            ->assertOk()
            ->assertJsonPath('status', 'delivered')
            ->json('receipts'));
        expect($receipts->pluck('consumer'))->toContain('mobile', 'widget');
    }

    CarbonImmutable::setTestNow('2026-08-07T12:00:00Z');
    $this->withToken($token->plainTextToken())->getJson('/api/status-summary')
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'counts' => [
                    'servers' => ['healthy' => 0, 'warn' => 0, 'down' => 1],
                    'websites' => ['healthy' => 0, 'warn' => 1, 'down' => 0],
                    'apis' => ['healthy' => 1, 'warn' => 0, 'down' => 0],
                ],
                'updated_at' => '2026-08-07T11:59:00.000000Z',
                'stale' => false,
            ],
        ]);
})->group('AC-domain-runtime-foundation-13');
