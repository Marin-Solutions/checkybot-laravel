<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Artisan;
use MarinSolutions\CheckybotLaravel\CheckRegistry;
use MarinSolutions\CheckybotLaravel\Facades\Checkybot;
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
            'dead_links' => [],
            'open_graph' => [],
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
            'dead_links' => [],
            'open_graph' => [],
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
            'dead_links' => [],
            'open_graph' => [],
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
            'dead_links' => [],
            'open_graph' => [],
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
            'dead_links' => [],
            'open_graph' => [],
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
                ['name' => 'test', 'url' => 'https://example.com', 'interval' => '5m'],
            ],
            'ssl' => [],
            'api' => [],
            'dead_links' => [],
            'open_graph' => [],
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
            'dead_links' => [],
            'open_graph' => [],
        ],
    ]);

    $mock = new MockHandler([
        new ConnectException(
            'Connection timed out',
            new Request('POST', '/api/v1/projects/1/checks/sync')
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

it('shows dry run output for dead link checks', function () {
    config([
        'checkybot-laravel.api_key' => 'test-key',
        'checkybot-laravel.project_id' => '1',
        'checkybot-laravel.checks' => [
            'uptime' => [],
            'ssl' => [],
            'api' => [],
            'dead_links' => [
                ['name' => 'homepage-links', 'url' => 'https://example.com', 'interval' => '1d'],
            ],
            'open_graph' => [],
        ],
    ]);

    $this->artisan('checkybot:sync --dry-run')
        ->expectsOutput('DRY RUN - No changes will be made')
        ->expectsOutputToContain('homepage-links')
        ->expectsOutputToContain('Link Checks')
        ->assertExitCode(0);
});

it('shows dry run output for open graph checks', function () {
    config([
        'checkybot-laravel.api_key' => 'test-key',
        'checkybot-laravel.project_id' => '1',
        'checkybot-laravel.checks' => [
            'uptime' => [],
            'ssl' => [],
            'api' => [],
            'dead_links' => [],
            'open_graph' => [
                ['name' => 'homepage-og', 'url' => 'https://example.com', 'interval' => '1d'],
            ],
        ],
    ]);

    $this->artisan('checkybot:sync --dry-run')
        ->expectsOutput('DRY RUN - No changes will be made')
        ->expectsOutputToContain('homepage-og')
        ->expectsOutputToContain('OpenGraph Checks')
        ->assertExitCode(0);
});

it('fails validation for invalid url in config', function () {
    config([
        'checkybot-laravel.api_key' => 'test-key',
        'checkybot-laravel.project_id' => '1',
        'checkybot-laravel.checks' => [
            'uptime' => [
                ['name' => 'homepage', 'url' => 'not-a-valid-url', 'interval' => '5m'],
            ],
            'ssl' => [],
            'api' => [],
            'dead_links' => [],
            'open_graph' => [],
        ],
    ]);

    $this->artisan('checkybot:sync')
        ->expectsOutput('Configuration validation failed:')
        ->expectsOutputToContain('has an invalid URL')
        ->assertExitCode(1);
});

it('fails validation for invalid interval in config', function () {
    config([
        'checkybot-laravel.api_key' => 'test-key',
        'checkybot-laravel.project_id' => '1',
        'checkybot-laravel.checks' => [
            'uptime' => [
                ['name' => 'homepage', 'url' => 'https://example.com', 'interval' => 'every-so-often'],
            ],
            'ssl' => [],
            'api' => [],
            'dead_links' => [],
            'open_graph' => [],
        ],
    ]);

    $this->artisan('checkybot:sync')
        ->expectsOutput('Configuration validation failed:')
        ->expectsOutputToContain('has an invalid interval')
        ->assertExitCode(1);
});

it('fails validation for missing url in config', function () {
    config([
        'checkybot-laravel.api_key' => 'test-key',
        'checkybot-laravel.project_id' => '1',
        'checkybot-laravel.checks' => [
            'uptime' => [
                ['name' => 'homepage', 'url' => '', 'interval' => '5m'],
            ],
            'ssl' => [],
            'api' => [],
            'dead_links' => [],
            'open_graph' => [],
        ],
    ]);

    $this->artisan('checkybot:sync')
        ->expectsOutput('Configuration validation failed:')
        ->expectsOutputToContain('is missing a URL')
        ->assertExitCode(1);
});

it('fails validation for missing interval in config', function () {
    config([
        'checkybot-laravel.api_key' => 'test-key',
        'checkybot-laravel.project_id' => '1',
        'checkybot-laravel.checks' => [
            'uptime' => [
                ['name' => 'homepage', 'url' => 'https://example.com', 'interval' => ''],
            ],
            'ssl' => [],
            'api' => [],
            'dead_links' => [],
            'open_graph' => [],
        ],
    ]);

    $this->artisan('checkybot:sync')
        ->expectsOutput('Configuration validation failed:')
        ->expectsOutputToContain('is missing an interval')
        ->assertExitCode(1);
});

it('counts and labels all seven config types without printing sensitive fields', function (): void {
    $secret = 'config-dry-run-secret';
    config([
        'checkybot-laravel.api_key' => 'api-'.$secret,
        'checkybot-laravel.project_id' => 'project',
        'checkybot-laravel.checks' => [
            'uptime' => [['name' => 'up', 'url' => 'https://example.com/up', 'interval' => '1m', 'headers' => ['Authorization' => $secret]]],
            'ssl' => [['name' => 'ssl', 'url' => 'https://example.com/ssl', 'interval' => '1d']],
            'api' => [['name' => 'api', 'url' => 'https://example.com/api', 'interval' => '5m', 'headers' => ['Cookie' => $secret]]],
            'dead_links' => [['name' => 'links', 'url' => 'https://example.com/links', 'interval' => '1d']],
            'open_graph' => [['name' => 'og', 'url' => 'https://example.com/og', 'interval' => '1h']],
            'domain_expiry' => [['name' => 'domain', 'url' => 'https://example.com', 'interval' => '1d']],
            'response_time_budget' => [['name' => 'budget', 'url' => 'https://example.com', 'interval' => '5m']],
        ],
    ]);

    expect(Artisan::call('checkybot:sync', ['--dry-run' => true]))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain('Found 7 checks to sync')
        ->and($output)->toContain('Uptime Checks (1):', 'Ssl Checks (1):', 'Api Checks (1):', 'Link Checks (1):')
        ->and($output)->toContain('OpenGraph Checks (1):', 'Domain Expiry Checks (1):', 'Response Time Budget Checks (1):')
        ->and($output)->not->toContain($secret)
        ->and($output)->not->toContain('Authorization', 'Cookie');
})->group('AC-laravel-sdk-monitor-definitions-7');

it('counts and labels all seven registry types without printing sensitive fields', function (): void {
    $secret = 'registry-dry-run-secret';
    app(CheckRegistry::class)->flush();
    config(['checkybot-laravel.api_key' => 'key', 'checkybot-laravel.project_id' => 'project']);

    Checkybot::uptime('up')->url('https://example.com/up')->everyMinute()->headers(['Authorization' => $secret]);
    Checkybot::ssl('ssl')->url('https://example.com/ssl')->daily();
    Checkybot::api('api')->url('https://example.com/api')->everyFiveMinutes()->withToken($secret);
    Checkybot::links('links')->url('https://example.com/links')->daily();
    Checkybot::openGraph('og')->url('https://example.com/og')->hourly();
    Checkybot::domainExpiry('domain')->url('https://example.com')->daily();
    Checkybot::responseTimeBudget('budget')->url('https://example.com')->everyFiveMinutes();

    expect(Artisan::call('checkybot:sync', ['--dry-run' => true]))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain('Found 7 checks to sync')
        ->and($output)->toContain('Uptime Checks (1):', 'Ssl Checks (1):', 'Api Checks (1):', 'Link Checks (1):')
        ->and($output)->toContain('OpenGraph Checks (1):', 'Domain Expiry Checks (1):', 'Response Time Budget Checks (1):')
        ->and($output)->not->toContain($secret)
        ->and($output)->not->toContain('Authorization');
})->group('AC-laravel-sdk-monitor-definitions-7');

it('normalizes canonical and legacy seven-type summaries including absent operation counts', function (): void {
    config([
        'checkybot-laravel.api_key' => 'key',
        'checkybot-laravel.project_id' => 'project',
        'checkybot-laravel.checks' => [],
    ]);
    $responses = [
        ['summary' => [
            'uptime' => ['created' => 1],
            'ssl' => ['updated' => 2],
            'api' => ['deleted' => 3],
            'dead_links' => ['created' => 4, 'updated' => 5, 'deleted' => 6],
            'open_graph' => [],
            'domain_expiry' => ['created' => 7],
            'response_time_budget' => ['updated' => 8],
        ]],
        ['summary' => [
            'uptime_checks' => ['created' => 11],
            'ssl_checks' => ['updated' => 12],
            'api_checks' => ['deleted' => 13],
            'link_checks' => ['created' => 14],
            'open_graph_checks' => ['updated' => 15],
            'domain_expiry_checks' => ['deleted' => 16],
            'response_time_budget_checks' => ['created' => 17],
        ]],
    ];
    $mock = new MockHandler(array_map(
        fn (array $body): Response => new Response(200, [], json_encode($body, JSON_THROW_ON_ERROR)),
        $responses,
    ));
    $this->app->instance(CheckybotClient::class, new CheckybotClient(
        baseUrl: 'https://checkybot.example',
        apiKey: 'key',
        projectId: 'project',
        retryDelay: 0,
        client: new Client(['handler' => HandlerStack::create($mock)]),
    ));

    expect(Artisan::call('checkybot:sync'))->toBe(0);
    $canonical = Artisan::output();
    expect($canonical)->toContain("Uptime Checks:\n    Created: 1\n    Updated: 0\n    Deleted: 0")
        ->and($canonical)->toContain("Ssl Checks:\n    Created: 0\n    Updated: 2\n    Deleted: 0")
        ->and($canonical)->toContain("Api Checks:\n    Created: 0\n    Updated: 0\n    Deleted: 3")
        ->and($canonical)->toContain("Domain Expiry Checks:\n    Created: 7\n    Updated: 0\n    Deleted: 0")
        ->and($canonical)->toContain("Response Time Budget Checks:\n    Created: 0\n    Updated: 8\n    Deleted: 0");

    expect(Artisan::call('checkybot:sync'))->toBe(0);
    $legacy = Artisan::output();
    expect($legacy)->toContain("Uptime Checks:\n    Created: 11\n    Updated: 0\n    Deleted: 0")
        ->and($legacy)->toContain("Link Checks:\n    Created: 14\n    Updated: 0\n    Deleted: 0")
        ->and($legacy)->toContain("OpenGraph Checks:\n    Created: 0\n    Updated: 15\n    Deleted: 0")
        ->and($legacy)->toContain("Domain Expiry Checks:\n    Created: 0\n    Updated: 0\n    Deleted: 16")
        ->and($legacy)->toContain("Response Time Budget Checks:\n    Created: 17\n    Updated: 0\n    Deleted: 0");
})->group('AC-laravel-sdk-monitor-definitions-7');

it('syncs with all five check types populated', function () {
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
            ],
            'ssl' => [
                ['name' => 'ssl1', 'url' => 'https://site1.com', 'interval' => '1d'],
            ],
            'api' => [
                ['name' => 'api1', 'url' => 'https://site1.com/api/health', 'interval' => '5m'],
            ],
            'dead_links' => [
                ['name' => 'links1', 'url' => 'https://site1.com', 'interval' => '1d'],
            ],
            'open_graph' => [
                ['name' => 'og1', 'url' => 'https://site1.com', 'interval' => '1d'],
            ],
        ],
    ]);

    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'message' => 'Checks synced successfully',
            'summary' => [
                'uptime_checks' => ['created' => 1, 'updated' => 0, 'deleted' => 0],
                'ssl_checks' => ['created' => 1, 'updated' => 0, 'deleted' => 0],
                'api_checks' => ['created' => 1, 'updated' => 0, 'deleted' => 0],
                'link_checks' => ['created' => 1, 'updated' => 0, 'deleted' => 0],
                'open_graph_checks' => ['created' => 1, 'updated' => 0, 'deleted' => 0],
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
