<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Models\ApiMonitorConfiguration;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Models\ApiMonitorHeader;

require_once __DIR__.'/Support/ApiBuilderFixtures.php';

beforeEach(function (): void {
    $directory = dirname(__DIR__, 3).'/build/api-builder-tests';
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    $this->apiBuilderDatabase = $directory.'/'.Str::uuid().'.sqlite';
    touch($this->apiBuilderDatabase);
    config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => $this->apiBuilderDatabase,
        'prefix' => '',
        'foreign_key_constraints' => true,
        'busy_timeout' => 10000,
    ]);
    config()->set('checkybot.api_builder.sample_exact_allowlist', [
        'https://sample.example.test/v1',
        'https://safe.example.test/start',
    ]);
    DB::purge('sqlite');
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_000000_create_monitor_foundation_tables.php')->up();
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_040000_create_api_monitor_builder_tables.php')->up();
    $this->inertiaHeaders = ['X-Inertia' => 'true', 'Accept' => 'application/json'];
});

afterEach(function (): void {
    DB::disconnect('sqlite');
    @unlink($this->apiBuilderDatabase);
});

it('persists one project-scoped versioned configuration while secrets remain encrypted and masked', function (): void {
    $project = (string) Str::uuid();
    $otherProject = (string) Str::uuid();
    $monitorUuid = (string) Str::uuid();
    apiBuilderMonitor($project, $monitorUuid);
    apiBuilderMonitor($otherProject, $monitorUuid);
    $secret = 'Bearer plaintext-must-never-leak';

    $this->actingAs(apiBuilderOperator($project, [$project, $otherProject]));
    $get = $this->withHeaders($this->inertiaHeaders)
        ->get('/checkybot/api-monitors/'.$monitorUuid.'/assertions')
        ->assertOk()
        ->assertJsonPath('component', 'CheckybotDashboard/ApiAssertionBuilder')
        ->assertJsonPath('props.monitor.project_id', $project)
        ->assertJsonPath('props.configuration.version', 1)
        ->assertJsonPath('props.sample', null);
    expect(ApiMonitorConfiguration::query()->where('project_id', $project)->where('monitor_id', $monitorUuid)->count())->toBe(1);
    $this->withHeaders($this->inertiaHeaders)->get('/checkybot/api-monitors/'.$monitorUuid.'/assertions')->assertOk();
    expect(ApiMonitorConfiguration::query()->where('project_id', $project)->where('monitor_id', $monitorUuid)->count())->toBe(1)
        ->and($get->getContent())->not->toContain($secret);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $saved = $this->putJson('/checkybot/api-monitors/'.$monitorUuid.'/assertions', apiBuilderPayload(1, [
        'headers' => [['name' => 'Authorization', 'action' => 'set', 'value' => $secret]],
        'assertions' => [['kind' => 'status', 'operator' => 'equals', 'expected' => 200]],
    ]))->assertOk()
        ->assertJsonPath('data.headers.0', ['name' => 'Authorization', 'mask' => '[REDACTED]', 'has_value' => true])
        ->assertJsonPath('data.version', 2);
    $queries = json_encode(DB::getQueryLog(), JSON_THROW_ON_ERROR);
    DB::disableQueryLog();

    $header = ApiMonitorHeader::query()->firstOrFail();
    $ciphertext = $header->encrypted_value;
    expect($ciphertext)->not->toContain($secret)
        ->and($queries)->not->toContain($secret)
        ->and($saved->getContent())->not->toContain($secret, $ciphertext)
        ->and($header->toJson())->not->toContain($secret, $ciphertext)
        ->and((new QueryException('sqlite', 'safe query', [], new RuntimeException('safe')))->getMessage())->not->toContain($secret);

    $preserved = $this->putJson('/checkybot/api-monitors/'.$monitorUuid.'/assertions', apiBuilderPayload(2, [
        'headers' => [['name' => 'Authorization', 'action' => 'preserve']],
        'assertions' => [['kind' => 'latency', 'operator' => 'less_than_or_equal', 'expected' => 500]],
    ]))->assertOk()->assertJsonPath('data.version', 3);
    expect(ApiMonitorHeader::query()->firstOrFail()->encrypted_value)->toBe($ciphertext)
        ->and($preserved->getContent())->not->toContain($secret, $ciphertext);

    $beforeStale = ApiMonitorConfiguration::query()->firstOrFail()->only(['endpoint', 'method', 'version']);
    $assertionsBeforeStale = DB::table('api_monitor_builder_assertions')->get()->map(fn ($row): array => (array) $row)->all();
    $staleSecret = 'stale-secret-never-written';
    $this->putJson('/checkybot/api-monitors/'.$monitorUuid.'/assertions', apiBuilderPayload(2, [
        'headers' => [['name' => 'X-New', 'action' => 'set', 'value' => $staleSecret]],
        'assertions' => [['kind' => 'status', 'operator' => 'equals', 'expected' => 503]],
    ]))->assertConflict()->assertJsonPath('message', 'The builder configuration changed; reload before saving.');
    expect(ApiMonitorConfiguration::query()->firstOrFail()->only(['endpoint', 'method', 'version']))->toBe($beforeStale)
        ->and(DB::table('api_monitor_builder_assertions')->get()->map(fn ($row): array => (array) $row)->all())->toBe($assertionsBeforeStale)
        ->and(json_encode(DB::table('api_monitor_builder_headers')->get(), JSON_THROW_ON_ERROR))->not->toContain($staleSecret);

    $this->putJson('/checkybot/api-monitors/'.$monitorUuid.'/assertions', apiBuilderPayload(3, [
        'headers' => [['name' => 'Authorization', 'action' => 'remove']],
    ]))->assertOk()->assertJsonPath('data.headers', []);
    expect(ApiMonitorHeader::query()->count())->toBe(0);
})->group('AC-web-dashboard-api-builder-4');

it('accepts ordered assertions and rejects bounded invalid writes and foreign access atomically', function (): void {
    $project = (string) Str::uuid();
    $foreignProject = (string) Str::uuid();
    $monitor = apiBuilderMonitor($project);
    $unconfigured = apiBuilderMonitor($project);
    $foreign = apiBuilderMonitor($foreignProject);
    $this->actingAs(apiBuilderOperator($project, [$project, $foreignProject]));

    $ordered = [
        ['kind' => 'status', 'operator' => 'in', 'expected' => [200, 201, 204]],
        ['kind' => 'latency', 'operator' => 'less_than_or_equal', 'expected' => 900],
        ['kind' => 'json_path', 'path' => '$.data[0].id', 'operator' => 'exists'],
        ['kind' => 'json_path', 'path' => '$.data[0].id', 'operator' => 'not_null'],
        ['kind' => 'json_path', 'path' => '$.data[0].id', 'operator' => 'type', 'expected' => 'integer'],
    ];
    $saved = $this->putJson('/checkybot/api-monitors/'.$monitor->monitor_id.'/assertions', apiBuilderPayload(1, [
        'assertions' => $ordered,
    ]))->assertOk()->assertJsonPath('data.version', 2);
    expect($saved->json('data.assertions'))->toEqual($ordered)
        ->and(DB::table('api_monitor_builder_assertions')->orderBy('position')->pluck('operator')->all())
        ->toBe(['in', 'less_than_or_equal', 'exists', 'not_null', 'type']);

    $snapshot = [
        'configuration' => DB::table('api_monitor_builder_configurations')->where('monitor_id', $monitor->monitor_id)->first(),
        'assertions' => DB::table('api_monitor_builder_assertions')->orderBy('position')->get()->all(),
        'headers' => DB::table('api_monitor_builder_headers')->get()->all(),
    ];
    $invalidPayloads = [
        apiBuilderPayload(2, ['headers' => [
            ['name' => 'X-Duplicate', 'action' => 'set', 'value' => 'one'],
            ['name' => 'x-duplicate', 'action' => 'set', 'value' => 'two'],
        ]]),
        apiBuilderPayload(2, ['headers' => array_fill(0, 51, ['name' => 'X-Same', 'action' => 'remove'])]),
        apiBuilderPayload(2, ['assertions' => array_fill(0, 51, ['kind' => 'status', 'operator' => 'equals', 'expected' => 200])]),
        apiBuilderPayload(2, ['assertions' => [['kind' => 'json_path', 'path' => '$..invalid', 'operator' => 'exists']]]),
        apiBuilderPayload(2, ['assertions' => [['kind' => 'latency', 'operator' => 'equals', 'expected' => 10]]]),
    ];
    foreach ($invalidPayloads as $payload) {
        $this->putJson('/checkybot/api-monitors/'.$monitor->monitor_id.'/assertions', $payload)
            ->assertUnprocessable()->assertJsonStructure(['message', 'errors']);
    }
    $this->putJson('/checkybot/api-monitors/'.$monitor->monitor_id.'/assertions', apiBuilderPayload(1, [
        'assertions' => [['kind' => 'status', 'operator' => 'equals', 'expected' => 500]],
    ]))->assertConflict();
    $this->putJson('/checkybot/api-monitors/'.$foreign->monitor_id.'/assertions', apiBuilderPayload(1))
        ->assertForbidden()->assertJsonPath('message', 'The operator cannot manage this API monitor.');
    $this->putJson('/checkybot/api-monitors/'.$unconfigured->monitor_id.'/assertions', apiBuilderPayload(2))
        ->assertConflict();
    $this->putJson('/checkybot/api-monitors/'.$unconfigured->monitor_id.'/assertions', apiBuilderPayload(1, [
        'headers' => [['name' => 'Authorization', 'action' => 'preserve']],
    ]))->assertUnprocessable()->assertJsonStructure(['errors' => ['headers.0.action']]);

    expect(DB::table('api_monitor_builder_configurations')->where('monitor_id', $monitor->monitor_id)->first())->toEqual($snapshot['configuration'])
        ->and(DB::table('api_monitor_builder_assertions')->orderBy('position')->get()->all())->toEqual($snapshot['assertions'])
        ->and(DB::table('api_monitor_builder_headers')->get()->all())->toEqual($snapshot['headers'])
        ->and(ApiMonitorConfiguration::query()->whereIn('monitor_id', [$foreign->monitor_id, $unconfigured->monitor_id])->doesntExist())->toBeTrue();
})->group('AC-web-dashboard-api-builder-7');
