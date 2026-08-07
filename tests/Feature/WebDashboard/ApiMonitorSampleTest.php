<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

require_once __DIR__.'/Support/ApiBuilderFixtures.php';

beforeEach(function (): void {
    $directory = dirname(__DIR__, 3).'/build/api-builder-sample-tests';
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    $this->apiBuilderSampleDatabase = $directory.'/'.Str::uuid().'.sqlite';
    touch($this->apiBuilderSampleDatabase);
    config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => $this->apiBuilderSampleDatabase,
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
});

afterEach(function (): void {
    DB::disconnect('sqlite');
    @unlink($this->apiBuilderSampleDatabase);
});

it('fetches bounded JSON through the real route and reveals preserved headers only to the outbound boundary', function (): void {
    $project = (string) Str::uuid();
    $monitor = apiBuilderMonitor($project);
    $secret = 'Bearer outbound-only-secret';
    $this->actingAs(apiBuilderOperator($project, [$project]));

    $this->putJson('/checkybot/api-monitors/'.$monitor->monitor_id.'/assertions', apiBuilderPayload(1, [
        'method' => 'GET',
        'headers' => [['name' => 'Authorization', 'action' => 'set', 'value' => $secret]],
    ]))->assertOk()->assertJsonPath('data.version', 2);

    Http::fake(function (Request $request) use ($secret) {
        expect($request->url())->toBe('https://sample.example.test/v1')
            ->and($request->hasHeader('Authorization', $secret))->toBeTrue();

        return Http::response(json_encode([
            'zeta' => [['id' => 7, 'name' => 'Ada']],
            'active' => true,
            'api_token' => 'upstream-secret-is-redacted',
            'odd key' => 4.25,
        ], JSON_THROW_ON_ERROR), 201, ['Content-Type' => 'application/json']);
    });

    $response = $this->postJson('/checkybot/api-monitors/'.$monitor->monitor_id.'/sample', apiBuilderPayload(2, [
        'method' => 'GET',
        'headers' => [['name' => 'Authorization', 'action' => 'preserve']],
        'request_body' => null,
    ]))->assertOk()
        ->assertJsonPath('data.mode', 'sample')
        ->assertJsonPath('data.status', 201)
        ->assertJsonPath('data.json.active', true)
        ->assertJsonPath('data.json.api_token', '[REDACTED]');

    expect($response->json('data.latency_ms'))->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and(array_column($response->json('data.paths'), 'path'))->toBe([
            '$', '$.active', '$.api_token', "\$['odd key']", '$.zeta', '$.zeta[0]', '$.zeta[0].id', '$.zeta[0].name',
        ])
        ->and(array_column($response->json('data.paths'), 'inferred_type'))->toBe([
            'object', 'boolean', 'string', 'number', 'array', 'object', 'integer', 'string',
        ])
        ->and($response->getContent())->not->toContain($secret, 'upstream-secret-is-redacted');
    Http::assertSentCount(1);
})->group('AC-web-dashboard-api-builder-5');

it('rejects malformed unsafe and redirect targets before connecting to them', function (): void {
    $project = (string) Str::uuid();
    $monitor = apiBuilderMonitor($project);
    $this->actingAs(apiBuilderOperator($project, [$project]));
    Http::preventStrayRequests();

    foreach (['not-a-url', 'http://user:password@example.com/private', 'http://127.0.0.1/admin', 'http://169.254.169.254/latest/meta-data'] as $endpoint) {
        $this->postJson('/checkybot/api-monitors/'.$monitor->monitor_id.'/sample', apiBuilderPayload(1, ['endpoint' => $endpoint]))
            ->assertUnprocessable()->assertJsonPath('manual_entry', true)->assertJsonStructure(['errors' => ['endpoint']]);
    }
    Http::assertNothingSent();

    Http::fake([
        'https://safe.example.test/start' => Http::response('', 302, ['Location' => 'http://127.0.0.1/internal']),
    ]);
    $redirect = $this->postJson('/checkybot/api-monitors/'.$monitor->monitor_id.'/sample', apiBuilderPayload(1, [
        'endpoint' => 'https://safe.example.test/start',
    ]))->assertStatus(502)
        ->assertJsonPath('error.code', 'fetch_failed')
        ->assertJsonPath('manual_entry', true);
    expect($redirect->getContent())->not->toContain('127.0.0.1', 'internal');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://safe.example.test/start');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '127.0.0.1'));
})->group('AC-web-dashboard-api-builder-6');

it('returns every typed redacted sample failure and enforces the response limit', function (): void {
    $project = (string) Str::uuid();
    $monitor = apiBuilderMonitor($project);
    $this->actingAs(apiBuilderOperator($project, [$project]));
    $credential = 'credential-must-not-leak';
    $upstreamBody = 'upstream-body-must-not-leak';
    Log::spy();

    Http::swap(new Factory);
    Http::fake(fn (): never => throw new ConnectionException('Operation timed out after 8000 milliseconds '.$credential));
    $timeout = $this->postJson('/checkybot/api-monitors/'.$monitor->monitor_id.'/sample', apiBuilderPayload(1))
        ->assertStatus(504)->assertJsonPath('error.code', 'fetch_timeout')
        ->assertJsonPath('error.upstream_status', null)->assertJsonPath('manual_entry', true);

    Http::swap(new Factory);
    Http::fake(fn (): never => throw new ConnectionException('Connection refused '.$credential));
    $transport = $this->postJson('/checkybot/api-monitors/'.$monitor->monitor_id.'/sample', apiBuilderPayload(1))
        ->assertStatus(502)->assertJsonPath('error.code', 'fetch_failed')->assertJsonPath('manual_entry', true);

    Http::swap(new Factory);
    Http::fake(['*' => Http::response($upstreamBody.' '.$credential, 200, ['Content-Type' => 'text/plain'])]);
    $nonJson = $this->postJson('/checkybot/api-monitors/'.$monitor->monitor_id.'/sample', apiBuilderPayload(1))
        ->assertStatus(502)->assertJsonPath('error.code', 'non_json')
        ->assertJsonPath('error.upstream_status', 200)->assertJsonPath('manual_entry', true);

    foreach ([401, 403] as $status) {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response($upstreamBody.' '.$credential, $status)]);
        $auth = $this->postJson('/checkybot/api-monitors/'.$monitor->monitor_id.'/sample', apiBuilderPayload(1))
            ->assertStatus(502)->assertJsonPath('error.code', 'upstream_auth')
            ->assertJsonPath('error.upstream_status', $status)->assertJsonPath('manual_entry', true);
        expect($auth->getContent())->not->toContain($credential, $upstreamBody);
    }

    Http::swap(new Factory);
    Http::fake(['*' => Http::response(str_repeat('x', 262145), 200, ['Content-Type' => 'application/json'])]);
    $large = $this->postJson('/checkybot/api-monitors/'.$monitor->monitor_id.'/sample', apiBuilderPayload(1))
        ->assertStatus(502)->assertJsonPath('error.code', 'fetch_failed')->assertJsonPath('manual_entry', true);

    foreach ([$timeout, $transport, $nonJson, $large] as $failure) {
        expect($failure->getContent())->not->toContain($credential, $upstreamBody);
    }
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('warning');
})->group('AC-web-dashboard-api-builder-6');
