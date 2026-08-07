<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Security\Foundation\ProjectTokenAbility;
use MarinSolutions\CheckybotLaravel\Models\MonitorState;
use MarinSolutions\CheckybotLaravel\Models\ProjectApiToken;

function createSummaryState(
    string $projectId,
    string $type,
    string $state,
    CarbonImmutable $observedAt,
): MonitorState {
    return MonitorState::query()->create([
        'project_id' => $projectId,
        'monitor_id' => (string) Str::uuid(),
        'monitor_type' => $type,
        'state' => $state,
        'severity' => $state === 'down' ? 'critical' : 'warn',
        'observed_at' => $observedAt,
    ]);
}

beforeEach(function (): void {
    $directory = dirname(__DIR__, 3).'/build/status-summary-tests';
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $this->statusSummaryDatabase = $directory.'/'.Str::uuid().'.sqlite';
    touch($this->statusSummaryDatabase);
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => $this->statusSummaryDatabase,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('sqlite');
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_000000_create_monitor_foundation_tables.php')->up();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    DB::disconnect('sqlite');
    @unlink($this->statusSummaryDatabase);
});

it('authenticates status read tokens and scopes the exact response to their project', function (): void {
    CarbonImmutable::setTestNow('2026-08-07T12:00:00Z');
    $projectId = (string) Str::uuid();
    $otherProjectId = (string) Str::uuid();
    createSummaryState($projectId, 'server', 'healthy', now()->toImmutable());
    createSummaryState($otherProjectId, 'api', 'down', now()->toImmutable());

    $this->getJson('/api/status-summary')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
    $this->withToken('not-a-project-token')->getJson('/api/status-summary')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);

    $withoutAbility = ProjectApiToken::issue($projectId, 'without-status-read', []);
    $this->withToken($withoutAbility->plainTextToken())->getJson('/api/status-summary')
        ->assertForbidden()
        ->assertExactJson(['message' => 'This token cannot read status summaries.']);

    $statusToken = ProjectApiToken::issue($projectId, 'mobile-widget', [ProjectTokenAbility::StatusRead]);
    $this->withToken($statusToken->plainTextToken())->getJson('/api/status-summary')
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'counts' => [
                    'servers' => ['healthy' => 1, 'warn' => 0, 'down' => 0],
                    'websites' => ['healthy' => 0, 'warn' => 0, 'down' => 0],
                    'apis' => ['healthy' => 0, 'warn' => 0, 'down' => 0],
                ],
                'updated_at' => '2026-08-07T12:00:00.000000Z',
                'stale' => false,
            ],
        ]);
})->group('AC-domain-runtime-foundation-11');

it('maps lifecycle cells, emits integer zeroes, and applies the exact freshness boundary', function (): void {
    CarbonImmutable::setTestNow('2026-08-07T12:00:00Z');
    $emptyProject = (string) Str::uuid();
    $emptyToken = ProjectApiToken::issue($emptyProject, 'empty', [ProjectTokenAbility::StatusRead]);

    $this->withToken($emptyToken->plainTextToken())->getJson('/api/status-summary')
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'counts' => [
                    'servers' => ['healthy' => 0, 'warn' => 0, 'down' => 0],
                    'websites' => ['healthy' => 0, 'warn' => 0, 'down' => 0],
                    'apis' => ['healthy' => 0, 'warn' => 0, 'down' => 0],
                ],
                'updated_at' => null,
                'stale' => true,
            ],
        ]);

    $projectId = (string) Str::uuid();
    createSummaryState($projectId, 'server', 'healthy', CarbonImmutable::now()->subSeconds(1200));
    createSummaryState($projectId, 'server', 'recovering', CarbonImmutable::now()->subSeconds(1100));
    createSummaryState($projectId, 'website', 'warn', CarbonImmutable::now()->subSeconds(1000));
    createSummaryState($projectId, 'api', 'down', CarbonImmutable::now()->subSeconds(900));
    createSummaryState((string) Str::uuid(), 'api', 'healthy', CarbonImmutable::now());
    $token = ProjectApiToken::issue($projectId, 'boundary', [ProjectTokenAbility::StatusRead]);

    $expected = [
        'data' => [
            'counts' => [
                'servers' => ['healthy' => 1, 'warn' => 1, 'down' => 0],
                'websites' => ['healthy' => 0, 'warn' => 1, 'down' => 0],
                'apis' => ['healthy' => 0, 'warn' => 0, 'down' => 1],
            ],
            'updated_at' => '2026-08-07T11:45:00.000000Z',
            'stale' => false,
        ],
    ];
    $this->withToken($token->plainTextToken())->getJson('/api/status-summary')->assertExactJson($expected);

    CarbonImmutable::setTestNow('2026-08-07T12:00:01Z');
    $expected['data']['stale'] = true;
    $this->withToken($token->plainTextToken())->getJson('/api/status-summary')->assertExactJson($expected);
})->group('AC-domain-runtime-foundation-12');
