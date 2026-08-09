<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use MarinSolutions\CheckybotLaravel\CheckRegistry;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\ContractValidator;
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

it('validates exact fluent and config HTTP bodies against the foundation contract', function (): void {
    $registry = app(CheckRegistry::class);
    $registry->flush();
    config([
        'checkybot-laravel.api_key' => 'contract-key',
        'checkybot-laravel.project_id' => 'contract-project',
    ]);

    Checkybot::uptime('up')->url('https://example.com/up')->everyMinute()->maxRedirects(4)->headers(['Accept' => 'text/html']);
    Checkybot::ssl('ssl')->url('https://example.com/ssl')->daily();
    Checkybot::api('api')->url('https://example.com/api')->everyFiveMinutes()
        ->headers(['Accept' => 'application/json'])->expectStatus(200)->maxLatency(750)->retries(2)
        ->expect('status')->toEqual('healthy');
    Checkybot::links('links')->url('https://example.com/links')->daily()->maxDepth(2)
        ->exclude(['/private/*'])->headers(['Accept' => 'text/html']);
    Checkybot::openGraph('og')->url('https://example.com/og')->hourly()
        ->requireTags(['og:title'])->headers(['Accept' => 'text/html']);
    Checkybot::domainExpiry('domain')->url('https://example.com')->daily()->warnDays(45);
    Checkybot::responseTimeBudget('budget')->url('https://example.com')->everyFiveMinutes()->percentile(95)->budgetMs(1800);

    $capture = function () use (&$capturedBody): CheckybotClient {
        $mock = new MockHandler([
            function ($request) use (&$capturedBody) {
                $capturedBody = json_decode((string) $request->getBody(), true);

                return new Response(200, [], json_encode(['message' => 'ok', 'summary' => []], JSON_THROW_ON_ERROR));
            },
        ]);

        return new CheckybotClient(
            baseUrl: 'https://checkybot.example',
            apiKey: 'contract-key',
            projectId: 'contract-project',
            retryDelay: 0,
            client: new Client(['handler' => HandlerStack::create($mock)]),
        );
    };

    $capturedBody = null;
    $this->app->instance(CheckybotClient::class, $capture());
    expect(Artisan::call('checkybot:sync'))->toBe(0);
    $fluentBody = $capturedBody;

    $registry->flush();
    config(['checkybot-laravel.checks' => [
        'uptime' => [[
            'name' => 'up', 'url' => 'https://example.com/up', 'interval' => '1m',
            'max_redirects' => 4, 'headers' => ['Accept' => 'text/html'],
        ]],
        'ssl' => [['name' => 'ssl', 'url' => 'https://example.com/ssl', 'interval' => '1d']],
        'api' => [[
            'name' => 'api', 'url' => 'https://example.com/api', 'interval' => '5m',
            'headers' => ['Accept' => 'application/json'], 'expected_status' => 200,
            'max_latency_ms' => 750, 'retry_count' => 2,
            'assertions' => [['kind' => 'json_path', 'operator' => 'equals', 'path' => 'status', 'operand' => 'healthy']],
        ]],
        'dead_links' => [[
            'name' => 'links', 'url' => 'https://example.com/links', 'interval' => '1d',
            'max_depth' => 2, 'exclude_paths' => ['/private/*'], 'headers' => ['Accept' => 'text/html'],
        ]],
        'open_graph' => [[
            'name' => 'og', 'url' => 'https://example.com/og', 'interval' => '1h',
            'required_tags' => ['og:title'], 'headers' => ['Accept' => 'text/html'],
        ]],
        'domain_expiry' => [[
            'name' => 'domain', 'url' => 'https://example.com', 'interval' => '1d', 'warn_days' => 45,
        ]],
        'response_time_budget' => [[
            'name' => 'budget', 'url' => 'https://example.com', 'interval' => '5m',
            'percentile' => 95, 'budget_ms' => 1800,
        ]],
    ]]);

    $capturedBody = null;
    $this->app->instance(CheckybotClient::class, $capture());
    expect(Artisan::call('checkybot:sync'))->toBe(0);
    $configBody = $capturedBody;

    expect($fluentBody)->toBe($configBody)
        ->and(array_keys($fluentBody))->toBe([
            'contract_version', 'uptime', 'ssl', 'api', 'dead_links', 'open_graph',
            'domain_expiry', 'response_time_budget',
        ])
        ->and($fluentBody['api'][0])->toMatchArray([
            'expected_status' => 200, 'max_latency_ms' => 750, 'retry_count' => 2,
        ])
        ->and($fluentBody['api'][0]['assertions'])->toBe([
            ['kind' => 'status', 'operator' => 'equals', 'operand' => 200, 'sort_order' => 1, 'is_active' => true],
            ['kind' => 'latency', 'operator' => 'less_than_or_equal', 'operand' => 750, 'sort_order' => 2, 'is_active' => true],
            ['kind' => 'json_path', 'operator' => 'equals', 'path' => 'status', 'operand' => 'healthy', 'sort_order' => 3, 'is_active' => true],
        ]);

    $contract = app(ContractValidator::class);
    expect($contract->validateCheckSync($fluentBody))->toBe($fluentBody)
        ->and($contract->validateCheckSync($configBody))->toBe($configBody);
})->group('AC-laravel-sdk-monitor-definitions-9');

it('rejects malformed package bodies at the foundation contract boundary', function (): void {
    $valid = app(CheckRegistry::class)->flush()->toArray();
    $contract = app(ContractValidator::class);
    $cases = [];

    $missing = $valid;
    unset($missing['domain_expiry']);
    $cases[] = $missing;
    $missingBothNewArrays = $valid;
    unset($missingBothNewArrays['domain_expiry'], $missingBothNewArrays['response_time_budget']);
    $cases[] = $missingBothNewArrays;
    $cases[] = [...$valid, 'contract_version' => 'check-sync.v999'];
    $cases[] = [...$valid, 'uptime' => [['name' => 'bad', 'url' => 'not-a-url', 'interval' => 'never']]];
    $cases[] = [...$valid, 'package_debug' => true];

    foreach ($cases as $payload) {
        expect(fn () => $contract->validateCheckSync($payload))->toThrow(ValidationException::class);
    }

    $this->postJson('/__harness/monitor-foundation/events', [
        'operation_id' => '33333333-3333-4333-8333-333333333333',
        'event_type' => 'contract.check_sync.probed',
        'payload' => $missingBothNewArrays,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['domain_expiry', 'response_time_budget']);
})->group('AC-laravel-sdk-monitor-definitions-9');

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
