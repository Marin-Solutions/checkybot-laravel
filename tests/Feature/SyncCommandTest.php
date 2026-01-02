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

it('shows dry run output for ssl checks', function () {
    config([
        'checkybot-laravel.api_key' => 'test-key',
        'checkybot-laravel.project_id' => '1',
        'checkybot-laravel.checks' => [
            'uptime' => [],
            'ssl' => [
                ['name' => 'main-ssl', 'url' => 'https://example.com', 'interval' => '1d'],
            ],
            'api' => [],
        ],
    ]);

    $this->artisan('checkybot:sync --dry-run')
        ->expectsOutput('DRY RUN - No changes will be made')
        ->expectsOutputToContain('main-ssl')
        ->expectsOutputToContain('Ssl Checks')
        ->assertExitCode(0);
});

it('shows dry run output for api checks', function () {
    config([
        'checkybot-laravel.api_key' => 'test-key',
        'checkybot-laravel.project_id' => '1',
        'checkybot-laravel.checks' => [
            'uptime' => [],
            'ssl' => [],
            'api' => [
                ['name' => 'health-endpoint', 'url' => 'https://example.com/api/health', 'interval' => '5m'],
            ],
        ],
    ]);

    $this->artisan('checkybot:sync --dry-run')
        ->expectsOutput('DRY RUN - No changes will be made')
        ->expectsOutputToContain('health-endpoint')
        ->expectsOutputToContain('Api Checks')
        ->assertExitCode(0);
});

it('shows dry run output for multiple check types', function () {
    config([
        'checkybot-laravel.api_key' => 'test-key',
        'checkybot-laravel.project_id' => '1',
        'checkybot-laravel.checks' => [
            'uptime' => [
                ['name' => 'homepage', 'url' => 'https://example.com', 'interval' => '5m'],
                ['name' => 'api-server', 'url' => 'https://api.example.com', 'interval' => '1m'],
            ],
            'ssl' => [
                ['name' => 'main-ssl', 'url' => 'https://example.com', 'interval' => '1d'],
            ],
            'api' => [
                ['name' => 'health', 'url' => 'https://example.com/health', 'interval' => '5m'],
            ],
        ],
    ]);

    $this->artisan('checkybot:sync --dry-run')
        ->expectsOutputToContain('Found 4 checks to sync')
        ->expectsOutputToContain('homepage')
        ->expectsOutputToContain('api-server')
        ->expectsOutputToContain('main-ssl')
        ->expectsOutputToContain('health')
        ->assertExitCode(0);
});

it('displays sync summary with created updated and deleted counts', function () {
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
                'uptime_checks' => ['created' => 1, 'updated' => 2, 'deleted' => 0],
                'ssl_checks' => ['created' => 0, 'updated' => 0, 'deleted' => 1],
                'api_checks' => ['created' => 0, 'updated' => 1, 'deleted' => 0],
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
        ->expectsOutputToContain('Sync Summary')
        ->expectsOutputToContain('Created: 1')
        ->expectsOutputToContain('Updated: 2')
        ->expectsOutputToContain('Deleted: 1')
        ->assertExitCode(0);
});

it('fails with duplicate check names', function () {
    config([
        'checkybot-laravel.api_key' => 'test-key',
        'checkybot-laravel.project_id' => '1',
        'checkybot-laravel.checks' => [
            'uptime' => [
                ['name' => 'homepage', 'url' => 'https://example.com', 'interval' => '5m'],
                ['name' => 'homepage', 'url' => 'https://example2.com', 'interval' => '5m'],
            ],
            'ssl' => [],
            'api' => [],
        ],
    ]);

    $this->artisan('checkybot:sync')
        ->expectsOutput('Configuration validation failed:')
        ->expectsOutputToContain('Duplicate uptime check names')
        ->assertExitCode(1);
});

it('shows zero checks when config is empty', function () {
    config([
        'checkybot-laravel.api_key' => 'test-key',
        'checkybot-laravel.project_id' => '1',
        'checkybot-laravel.checks' => [
            'uptime' => [],
            'ssl' => [],
            'api' => [],
        ],
    ]);

    $this->artisan('checkybot:sync --dry-run')
        ->expectsOutputToContain('Found 0 checks to sync')
        ->assertExitCode(0);
});

it('displays starting message', function () {
    config([
        'checkybot-laravel.api_key' => 'test-key',
        'checkybot-laravel.project_id' => '1',
        'checkybot-laravel.checks' => ['uptime' => [], 'ssl' => [], 'api' => []],
    ]);

    $this->artisan('checkybot:sync --dry-run')
        ->expectsOutput('Checkybot Sync Starting...')
        ->assertExitCode(0);
});

it('shows check details in dry run including url and interval', function () {
    config([
        'checkybot-laravel.api_key' => 'test-key',
        'checkybot-laravel.project_id' => '1',
        'checkybot-laravel.checks' => [
            'uptime' => [
                ['name' => 'my-site', 'url' => 'https://mysite.com', 'interval' => '10m'],
            ],
            'ssl' => [],
            'api' => [],
        ],
    ]);

    $this->artisan('checkybot:sync --dry-run')
        ->expectsOutputToContain('my-site (https://mysite.com) every 10m')
        ->assertExitCode(0);
});

it('handles validation error from api with detailed message', function () {
    config([
        'checkybot-laravel.api_key' => 'test-key',
        'checkybot-laravel.project_id' => '1',
        'checkybot-laravel.base_url' => 'https://checkybot.com',
        'checkybot-laravel.timeout' => 30,
        'checkybot-laravel.retry_times' => 3,
        'checkybot-laravel.retry_delay' => 1000,
        'checkybot-laravel.checks' => [
            'uptime' => [
                ['name' => 'test', 'url' => 'invalid-url', 'interval' => '5m'],
            ],
            'ssl' => [],
            'api' => [],
        ],
    ]);

    $mock = new MockHandler([
        new Response(422, [], json_encode([
            'message' => 'The given data was invalid.',
            'errors' => [
                'uptime_checks.0.url' => ['The url field must be a valid URL.'],
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
        ->expectsOutputToContain('Sync failed: Validation failed')
        ->assertExitCode(1);
});

it('fails when both api_key and project_id are missing', function () {
    config([
        'checkybot-laravel.api_key' => null,
        'checkybot-laravel.project_id' => null,
    ]);

    $this->artisan('checkybot:sync')
        ->expectsOutput('Configuration validation failed:')
        ->expectsOutput('  - CHECKYBOT_API_KEY is not configured')
        ->expectsOutput('  - CHECKYBOT_PROJECT_ID is not configured')
        ->assertExitCode(1);
});

it('syncs with all check types populated', function () {
    config([
        'checkybot-laravel.api_key' => 'test-key',
        'checkybot-laravel.project_id' => '1',
        'checkybot-laravel.base_url' => 'https://checkybot.com',
        'checkybot-laravel.timeout' => 30,
        'checkybot-laravel.retry_times' => 3,
        'checkybot-laravel.retry_delay' => 1000,
        'checkybot-laravel.checks' => [
            'uptime' => [
                ['name' => 'site1', 'url' => 'https://site1.com', 'interval' => '5m'],
                ['name' => 'site2', 'url' => 'https://site2.com', 'interval' => '10m'],
            ],
            'ssl' => [
                ['name' => 'ssl1', 'url' => 'https://site1.com', 'interval' => '1d'],
            ],
            'api' => [
                ['name' => 'api1', 'url' => 'https://site1.com/api/health', 'interval' => '5m'],
                ['name' => 'api2', 'url' => 'https://site2.com/api/health', 'interval' => '5m'],
            ],
        ],
    ]);

    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'message' => 'Checks synced successfully',
            'summary' => [
                'uptime_checks' => ['created' => 2, 'updated' => 0, 'deleted' => 0],
                'ssl_checks' => ['created' => 1, 'updated' => 0, 'deleted' => 0],
                'api_checks' => ['created' => 2, 'updated' => 0, 'deleted' => 0],
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
        ->expectsOutputToContain('Found 5 checks to sync')
        ->expectsOutputToContain('Sync completed successfully')
        ->assertExitCode(0);
});

it('handles network timeout error gracefully', function () {
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
        new \GuzzleHttp\Exception\ConnectException(
            'Connection timed out',
            new \GuzzleHttp\Psr7\Request('POST', '/api/v1/projects/1/checks/sync')
        ),
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
