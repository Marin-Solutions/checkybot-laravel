<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use MarinSolutions\CheckybotLaravel\CheckRegistry;
use MarinSolutions\CheckybotLaravel\Facades\Checkybot;
use MarinSolutions\CheckybotLaravel\Http\CheckybotClient;

beforeEach(function () {
    // Flush registry before each test
    app(CheckRegistry::class)->flush();

    config([
        'checkybot-laravel.api_key' => 'test-key',
        'checkybot-laravel.project_id' => '1',
        'checkybot-laravel.base_url' => 'https://checkybot.com',
        'checkybot-laravel.timeout' => 30,
        'checkybot-laravel.retry_times' => 3,
        'checkybot-laravel.retry_delay' => 1000,
        'checkybot-laravel.checks' => [
            'uptime' => [],
            'ssl' => [],
            'api' => [],
        ],
    ]);
});

it('syncs checks defined via fluent api', function () {
    Checkybot::uptime('homepage')
        ->url('https://example.com')
        ->everyFiveMinutes();

    Checkybot::ssl('main-ssl')
        ->url('https://example.com')
        ->daily();

    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'message' => 'Checks synced successfully',
            'summary' => [
                'uptime_checks' => ['created' => 1, 'updated' => 0, 'deleted' => 0],
                'ssl_checks' => ['created' => 1, 'updated' => 0, 'deleted' => 0],
                'api_checks' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
            ],
        ])),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $guzzle = new Client(['handler' => $handlerStack]);

    $client = new CheckybotClient(
        baseUrl: 'https://checkybot.com',
        apiKey: 'test-key',
        projectId: '1',
        client: $guzzle
    );

    $this->app->instance(CheckybotClient::class, $client);

    $this->artisan('checkybot:sync')
        ->expectsOutputToContain('Found 2 checks to sync')
        ->expectsOutputToContain('Sync completed successfully')
        ->assertExitCode(0);
});

it('shows dry run output for fluent api checks', function () {
    Checkybot::uptime('homepage')
        ->url('https://example.com')
        ->everyFiveMinutes();

    Checkybot::api('health')
        ->url('https://example.com/api/health')
        ->everyMinute()
        ->expect('status')->toEqual('healthy');

    $this->artisan('checkybot:sync --dry-run')
        ->expectsOutput('DRY RUN - No changes will be made')
        ->expectsOutputToContain('homepage')
        ->expectsOutputToContain('health')
        ->expectsOutputToContain('Found 2 checks to sync')
        ->assertExitCode(0);
});

it('validates duplicate check names in fluent api', function () {
    Checkybot::uptime('homepage')
        ->url('https://example.com')
        ->everyFiveMinutes();

    Checkybot::uptime('homepage')
        ->url('https://example2.com')
        ->everyFiveMinutes();

    $this->artisan('checkybot:sync')
        ->expectsOutput('Configuration validation failed:')
        ->expectsOutputToContain('Duplicate uptime check names')
        ->assertExitCode(1);
});

it('prefers fluent api over config array when both defined', function () {
    // Define checks in config
    config([
        'checkybot-laravel.checks' => [
            'uptime' => [
                ['name' => 'config-check', 'url' => 'https://config.example.com', 'interval' => '5m'],
            ],
            'ssl' => [],
            'api' => [],
        ],
    ]);

    // Define checks via fluent API
    Checkybot::uptime('fluent-check')
        ->url('https://fluent.example.com')
        ->everyFiveMinutes();

    // Fluent API should take precedence
    $this->artisan('checkybot:sync --dry-run')
        ->expectsOutputToContain('fluent-check')
        ->expectsOutputToContain('Found 1 checks to sync')
        ->assertExitCode(0);
});

it('sends correct payload structure from fluent api', function () {
    $capturedPayload = null;

    Checkybot::uptime('homepage')
        ->url('https://example.com')
        ->everyFiveMinutes()
        ->maxRedirects(5);

    Checkybot::ssl('main-ssl')
        ->url('https://example.com')
        ->daily();

    Checkybot::api('health')
        ->url('https://example.com/api/health')
        ->everyMinute()
        ->withToken('secret')
        ->expect('status')->toEqual('healthy');

    $mock = new MockHandler([
        function ($request) use (&$capturedPayload) {
            $capturedPayload = json_decode($request->getBody()->getContents(), true);

            return new Response(200, [], json_encode([
                'message' => 'Success',
                'summary' => [
                    'uptime_checks' => ['created' => 1, 'updated' => 0, 'deleted' => 0],
                    'ssl_checks' => ['created' => 1, 'updated' => 0, 'deleted' => 0],
                    'api_checks' => ['created' => 1, 'updated' => 0, 'deleted' => 0],
                ],
            ]));
        },
    ]);

    $handlerStack = HandlerStack::create($mock);
    $guzzle = new Client(['handler' => $handlerStack]);

    $client = new CheckybotClient(
        baseUrl: 'https://checkybot.com',
        apiKey: 'test-key',
        projectId: '1',
        client: $guzzle
    );

    $this->app->instance(CheckybotClient::class, $client);

    $this->artisan('checkybot:sync')->assertExitCode(0);

    expect($capturedPayload['uptime_checks'])->toHaveCount(1)
        ->and($capturedPayload['uptime_checks'][0]['name'])->toBe('homepage')
        ->and($capturedPayload['uptime_checks'][0]['max_redirects'])->toBe(5)
        ->and($capturedPayload['ssl_checks'])->toHaveCount(1)
        ->and($capturedPayload['api_checks'])->toHaveCount(1)
        ->and($capturedPayload['api_checks'][0]['headers']['Authorization'])->toBe('Bearer secret')
        ->and($capturedPayload['api_checks'][0]['assertions'])->toHaveCount(1);
});

it('falls back to config when no fluent checks defined', function () {
    // Only config, no fluent API
    config([
        'checkybot-laravel.checks' => [
            'uptime' => [
                ['name' => 'config-check', 'url' => 'https://config.example.com', 'interval' => '5m'],
            ],
            'ssl' => [],
            'api' => [],
        ],
    ]);

    $this->artisan('checkybot:sync --dry-run')
        ->expectsOutputToContain('config-check')
        ->expectsOutputToContain('Found 1 checks to sync')
        ->assertExitCode(0);
});
