<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use MarinSolutions\CheckybotLaravel\CheckRegistry;
use MarinSolutions\CheckybotLaravel\Exceptions\CheckybotSyncException;
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
            'dead_links' => [],
            'open_graph' => [],
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
                'link_checks' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
                'open_graph_checks' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
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

    Checkybot::links('homepage-links')
        ->url('https://example.com')
        ->daily();

    Checkybot::openGraph('homepage-og')
        ->url('https://example.com')
        ->daily();

    $exitCode = Artisan::call('checkybot:sync', ['--dry-run' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('DRY RUN - No changes will be made')
        ->and($output)->toContain('homepage')
        ->and($output)->toContain('health')
        ->and($output)->toContain('homepage-links')
        ->and($output)->toContain('homepage-og')
        ->and($output)->toContain('Found 4 checks to sync');
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

    Checkybot::links('homepage-links')
        ->url('https://example.com')
        ->daily()
        ->maxDepth(1)
        ->exclude(['/admin/*']);

    Checkybot::openGraph('homepage-og')
        ->url('https://example.com')
        ->daily()
        ->requireTags(['og:title', 'og:image']);

    $mock = new MockHandler([
        function ($request) use (&$capturedPayload) {
            $capturedPayload = json_decode($request->getBody()->getContents(), true);

            return new Response(200, [], json_encode([
                'message' => 'Success',
                'summary' => [
                    'uptime_checks' => ['created' => 1, 'updated' => 0, 'deleted' => 0],
                    'ssl_checks' => ['created' => 1, 'updated' => 0, 'deleted' => 0],
                    'api_checks' => ['created' => 1, 'updated' => 0, 'deleted' => 0],
                    'link_checks' => ['created' => 1, 'updated' => 0, 'deleted' => 0],
                    'open_graph_checks' => ['created' => 1, 'updated' => 0, 'deleted' => 0],
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

    expect($capturedPayload['contract_version'])->toBe('check-sync.v1')
        ->and($capturedPayload['uptime'])->toHaveCount(1)
        ->and($capturedPayload['uptime'][0]['name'])->toBe('homepage')
        ->and($capturedPayload['uptime'][0]['max_redirects'])->toBe(5)
        ->and($capturedPayload['ssl'])->toHaveCount(1)
        ->and($capturedPayload['api'])->toHaveCount(1)
        ->and($capturedPayload['api'][0]['headers']['Authorization'])->toBe('Bearer secret')
        ->and($capturedPayload['api'][0]['assertions'])->toHaveCount(1)
        ->and($capturedPayload['dead_links'])->toHaveCount(1)
        ->and($capturedPayload['dead_links'][0]['max_depth'])->toBe(1)
        ->and($capturedPayload['dead_links'][0]['exclude_paths'])->toBe(['/admin/*'])
        ->and($capturedPayload['open_graph'])->toHaveCount(1)
        ->and($capturedPayload['open_graph'][0]['required_tags'])->toBe(['og:title', 'og:image'])
        ->and($capturedPayload['domain_expiry'])->toBeEmpty()
        ->and($capturedPayload['response_time_budget'])->toBeEmpty();
});

it('keeps a fixed credential corpus only in the captured outbound HTTPS body', function (): void {
    $secrets = [
        'authorization-corpus-7f3d',
        'bearer-corpus-91aa',
        'cookie-corpus-245c',
        'custom-header-corpus-c880',
    ];
    Log::spy();

    $check = Checkybot::api('secret-probe')
        ->url('https://example.com/private')
        ->headers([
            'Authorization' => $secrets[0],
            'X-Bearer-Token' => $secrets[1],
            'Cookie' => $secrets[2],
            'X-Custom-Secret' => $secrets[3],
        ])
        ->everyMinute();

    Artisan::call('checkybot:sync', ['--dry-run' => true]);
    $dryRun = Artisan::output();

    ob_start();
    var_dump($check, app(CheckRegistry::class));
    $debug = (string) ob_get_clean();
    $safeSerialization = json_encode([$check, app(CheckRegistry::class)], JSON_THROW_ON_ERROR)
        .serialize($check).serialize(app(CheckRegistry::class));
    $snapshot = json_encode($check->toSafeArray(), JSON_THROW_ON_ERROR);
    $integrationEvidence = json_encode(['name' => $check->getName(), 'contract_version' => 'check-sync.v1'], JSON_THROW_ON_ERROR);

    $capturedBody = null;
    $capturedUri = null;
    $successMock = new MockHandler([
        function ($request) use (&$capturedBody, &$capturedUri) {
            $capturedBody = (string) $request->getBody();
            $capturedUri = (string) $request->getUri();

            return new Response(200, [], json_encode([
                'message' => 'Success',
                'summary' => ['api' => ['created' => 1, 'updated' => 0, 'deleted' => 0]],
            ]));
        },
    ]);
    $successStack = HandlerStack::create($successMock);
    $this->app->instance(CheckybotClient::class, new CheckybotClient(
        baseUrl: 'https://capture.example',
        apiKey: 'non-corpus-api-key',
        projectId: 'project',
        client: new Client(['base_uri' => 'https://capture.example', 'handler' => $successStack]),
    ));

    Artisan::call('checkybot:sync');
    $summary = Artisan::output();

    $failureText = implode(' | ', $secrets);
    $failureMock = new MockHandler([new Response(422, [], json_encode([
        'message' => $failureText,
        'errors' => ['api.0.headers' => [$failureText]],
    ]))]);
    $failingClient = new CheckybotClient(
        baseUrl: 'https://capture.example',
        apiKey: 'non-corpus-api-key',
        projectId: 'project',
        client: new Client(['base_uri' => 'https://capture.example', 'handler' => HandlerStack::create($failureMock)]),
    );
    $exceptionText = '';
    try {
        $failingClient->syncChecks(app(CheckRegistry::class)->toArray());
    } catch (CheckybotSyncException $exception) {
        $exceptionText = $exception->getMessage();
    }

    foreach ($secrets as $secret) {
        expect($capturedBody)->toContain($secret);
        foreach ([$dryRun, $summary, $exceptionText, $debug, $safeSerialization, $snapshot, $integrationEvidence] as $surface) {
            expect($surface)->not->toContain($secret);
        }
    }
    expect($capturedUri)->toStartWith('https://capture.example/');
    Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context) use ($secrets): bool {
        $encoded = $message.json_encode($context);

        return collect($secrets)->every(fn (string $secret): bool => ! str_contains($encoded, $secret));
    })->once();
})->group('AC-laravel-sdk-monitor-definitions-3');

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
