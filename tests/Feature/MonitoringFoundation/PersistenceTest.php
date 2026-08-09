<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Models\MonitorState;
use MarinSolutions\CheckybotLaravel\Models\MonitorTransition;
use MarinSolutions\CheckybotLaravel\Models\OutboxEvent;

beforeEach(function (): void {
    config()->set('database.default', 'monitor_foundation_test');
    config()->set('database.connections.monitor_foundation_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('monitor_foundation_test');
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_000000_create_monitor_foundation_tables.php')->up();
});

it('enforces current-state identity, enum constraints, UUIDs, operation order and outbox idempotency', function (): void {
    $projectId = (string) Str::uuid();
    $otherProjectId = (string) Str::uuid();
    $monitorId = (string) Str::uuid();
    $operationId = (string) Str::uuid();

    $state = MonitorState::query()->create([
        'project_id' => $projectId,
        'monitor_id' => $monitorId,
        'monitor_type' => 'server',
        'state' => 'healthy',
        'severity' => 'warn',
        'observed_at' => now(),
    ]);

    expect(Str::isUuid($state->public_id))->toBeTrue()
        ->and(MonitorState::query()->forProject($otherProjectId)->count())->toBe(0)
        ->and(MonitorState::query()->forProject($projectId)->pluck('project_id')->all())->toBe([$projectId]);

    expect(fn () => MonitorState::query()->create([
        'project_id' => $projectId,
        'monitor_id' => $monitorId,
        'monitor_type' => 'server',
        'state' => 'down',
        'severity' => 'critical',
        'observed_at' => now(),
    ]))->toThrow(QueryException::class);

    foreach ([
        ['monitor_type' => 'tcp', 'state' => 'healthy', 'severity' => 'warn'],
        ['monitor_type' => 'api', 'state' => 'paused', 'severity' => 'warn'],
        ['monitor_type' => 'website', 'state' => 'down', 'severity' => 'info'],
    ] as $invalidEnums) {
        expect(fn () => DB::table('monitor_states')->insert([
            'public_id' => (string) Str::uuid(),
            'project_id' => $projectId,
            'monitor_id' => (string) Str::uuid(),
            ...$invalidEnums,
            'observed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    }

    $transition = MonitorTransition::query()->create([
        'project_id' => $projectId,
        'monitor_id' => $monitorId,
        'monitor_type' => 'server',
        'operation_id' => $operationId,
        'operation_sequence' => 1,
        'from_state' => 'healthy',
        'to_state' => 'down',
        'severity' => 'critical',
        'monitor_filter' => ['types' => ['server'], 'states' => ['down'], 'severities' => ['critical']],
        'occurred_at' => now(),
    ]);

    expect(Str::isUuid($transition->public_id))->toBeTrue()
        ->and(fn () => $transition->update(['to_state' => 'healthy']))->toThrow(LogicException::class)
        ->and(fn () => $transition->delete())->toThrow(LogicException::class)
        ->and(MonitorTransition::query()->forProject($otherProjectId)->count())->toBe(0);

    MonitorTransition::query()->create([
        'project_id' => $projectId,
        'monitor_id' => $monitorId,
        'monitor_type' => 'server',
        'operation_id' => (string) Str::uuid(),
        'operation_sequence' => 2,
        'from_state' => 'down',
        'to_state' => 'recovering',
        'severity' => 'warn',
        'monitor_filter' => ['types' => ['server'], 'states' => ['recovering'], 'severities' => ['warn']],
        'occurred_at' => now()->addSecond(),
    ]);
    expect(MonitorTransition::query()->orderBy('operation_sequence')->pluck('operation_sequence')->all())->toBe([1, 2]);

    expect(fn () => MonitorTransition::query()->create([
        'project_id' => $projectId,
        'monitor_id' => $monitorId,
        'monitor_type' => 'server',
        'operation_id' => (string) Str::uuid(),
        'operation_sequence' => 1,
        'from_state' => 'down',
        'to_state' => 'recovering',
        'severity' => 'warn',
        'monitor_filter' => [],
        'occurred_at' => now(),
    ]))->toThrow(QueryException::class);

    $outbox = OutboxEvent::query()->create([
        'operation_id' => $operationId,
        'event_type' => 'monitor.transitioned',
        'contract_version' => 'monitor-foundation.v1',
        'payload' => [],
    ]);
    expect(Str::isUuid($outbox->public_id))->toBeTrue();
    expect(fn () => OutboxEvent::query()->create([
        'operation_id' => $operationId,
        'event_type' => 'monitor.transitioned',
        'contract_version' => 'monitor-foundation.v1',
        'payload' => [],
    ]))->toThrow(QueryException::class);

    $stateIndexes = collect(DB::select("PRAGMA index_list('monitor_states')"))->pluck('name');
    $historyIndexes = collect(DB::select("PRAGMA index_list('monitor_transitions')"))->pluck('name');
    expect($stateIndexes)->toContain('monitor_states_project_summary_index', 'monitor_states_identity_unique')
        ->and($historyIndexes)->toContain('monitor_transitions_project_history_index', 'monitor_transitions_order_unique');
})->group('AC-domain-runtime-foundation-2');
