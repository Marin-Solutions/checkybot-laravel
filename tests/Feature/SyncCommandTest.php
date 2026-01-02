<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use MarinSolutions\CheckybotLaravel\Http\CheckybotClient;

it('fails when api_key is not configured', function () {
    config([
        'checkybot-laravel.api_key' => null,
        'checkybot-laravel.project_id' => '1',
    ]);

    $this->artisan('checkybot:sync')
        ->expectsOutput('Configuration validation failed:')
        ->expectsOutput('  - CHECKYBOT_API_KEY is not configured')
        ->assertExitCode(1);
});

it('fails when project_id is not configured', function () {
    config([
        'checkybot-laravel.api_key' => 'test-key',
        'checkybot-laravel.project_id' => null,
    ]);

    $this->artisan('checkybot:sync')
        ->expectsOutput('Configuration validation failed:')
        ->expectsOutput('  - CHECKYBOT_PROJECT_ID is not configured')
        ->assertExitCode(1);
});

it('shows dry run output without making api call', function () {
    config([
        'checkybot-laravel.api_key' => 'test-key',
        'checkybot-laravel.project_id' => '1',
        'checkybot-laravel.checks' => [
            'uptime' => [
                ['name' => 'homepage', 'url' => 'https://example.com', 'interval' => '5m'],
            ],
            'ssl' => [],
            'api' => [],
        ],
    ]);

    $this->artisan('checkybot:sync --dry-run')
        ->expectsOutput('DRY RUN - No changes will be made')
        ->expectsOutputToContain('homepage')
        ->assertExitCode(0);
});

it('syncs checks successfully', function () {
    config([
        'checkybot-laravel.api_key' => 'test-key',
        'checkybot-laravel.project_id' => '1',
        'checkybot-laravel.base_url' => 'https://checkybot.com',
        'checkybot-laravel.timeout' => 30,
        'checkybot-laravel.retry_times' => 3,
        'checkybot-laravel.retry_delay' => 1000,
        'checkybot-laravel.checks' => [
            'uptime' => [
                ['name' => 'homepage', 'url' => 'https://example.com', 'interval' => '5m'],
            ],
            'ssl' => [],
            'api' => [],
        ],
    ]);

    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'message' => 'Checks synced successfully',
            'summary' => [
                'uptime_checks' => ['created' => 1, 'updated' => 0, 'deleted' => 0],
                'ssl_checks' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
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
        ->expectsOutputToContain('Sync completed successfully')
        ->assertExitCode(0);
});

it('handles api errors gracefully', function () {
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

    $mock = new MockHandler([
        new Response(403, [], json_encode([
            'message' => 'You do not have permission to manage this project.',
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
        ->expectsOutputToContain('Sync failed')
        ->assertExitCode(1);
});
