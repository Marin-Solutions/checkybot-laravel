<?php

use MarinSolutions\CheckybotLaravel\CheckRegistry;
use MarinSolutions\CheckybotLaravel\ConfigValidator;

beforeEach(function (): void {
    $this->validator = new ConfigValidator;
    $this->credentials = ['api_key' => 'test-key', 'project_id' => 'project'];
});

function sdkConfigDefinition(string $type, array $check): array
{
    return [
        'api_key' => 'test-key',
        'project_id' => 'project',
        'checks' => [$type => [$check]],
    ];
}

it('serializes equivalent fluent and config new definitions identically with defaults', function (): void {
    $registry = new CheckRegistry;
    $registry->domainExpiry('domain')->url('https://example.com')->daily();
    $registry->responseTimeBudget('budget')->url('https://example.com')->everyFiveMinutes();

    $config = $this->credentials + ['checks' => [
        'domain_expiry' => [['name' => 'domain', 'url' => 'https://example.com', 'interval' => '1d']],
        'response_time_budget' => [['name' => 'budget', 'url' => 'https://example.com', 'interval' => '5m']],
    ]];

    expect($this->validator->validateWithRegistry($this->credentials, $registry)['valid'])->toBeTrue()
        ->and($this->validator->validate($config)['valid'])->toBeTrue()
        ->and($registry->toArray())->toBe($this->validator->transformPayload($config))
        ->and($registry->toArray()['domain_expiry'][0]['warn_days'])->toBe(30)
        ->and($registry->toArray()['response_time_budget'][0])->toMatchArray(['percentile' => 95, 'budget_ms' => 2000]);
})->group('AC-laravel-sdk-monitor-definitions-1');

it('preserves valid explicit thresholds in fluent and config definitions', function (): void {
    $registry = new CheckRegistry;
    $registry->domainExpiry('domain')->url('https://example.com')->daily()->warnDays(60);
    $registry->responseTimeBudget('budget')->url('https://example.com')->everyMinute()->percentile(99)->budgetMs(850);

    $config = $this->credentials + ['checks' => [
        'domain_expiry' => [['name' => 'domain', 'url' => 'https://example.com', 'interval' => '1d', 'warn_days' => 60]],
        'response_time_budget' => [['name' => 'budget', 'url' => 'https://example.com', 'interval' => '1m', 'percentile' => 99, 'budget_ms' => 850]],
    ]];

    expect($this->validator->validateWithRegistry($this->credentials, $registry)['valid'])->toBeTrue()
        ->and($this->validator->validate($config)['valid'])->toBeTrue()
        ->and($registry->toArray())->toBe($this->validator->transformPayload($config));
})->group('AC-laravel-sdk-monitor-definitions-1');

it('rejects duplicate names independently for every config type', function (string $type): void {
    $check = ['name' => 'duplicate', 'url' => 'https://example.com', 'interval' => '5m'];
    $config = $this->credentials + ['checks' => [$type => [$check, $check]]];

    expect($this->validator->validate($config)['errors'])->toContain("Duplicate {$type} check names found: duplicate");
})->with(['uptime', 'ssl', 'api', 'dead_links', 'open_graph', 'domain_expiry', 'response_time_budget'])
    ->group('AC-laravel-sdk-monitor-definitions-4');

it('rejects duplicate names independently for every fluent type', function (string $factory, string $type): void {
    $registry = new CheckRegistry;
    $registry->{$factory}('duplicate')->url('https://example.com');
    $registry->{$factory}('duplicate')->url('https://example.com');

    expect($this->validator->validateWithRegistry($this->credentials, $registry)['errors'])
        ->toContain("Duplicate {$type} check names found: duplicate");
})->with([
    ['uptime', 'uptime'], ['ssl', 'ssl'], ['api', 'api'], ['links', 'dead_links'],
    ['openGraph', 'open_graph'], ['domainExpiry', 'domain_expiry'], ['responseTimeBudget', 'response_time_budget'],
])->group('AC-laravel-sdk-monitor-definitions-4');

it('rejects invalid URLs and intervals from config before serialization', function (string $url, string $interval): void {
    $result = $this->validator->validate(sdkConfigDefinition('uptime', [
        'name' => 'invalid', 'url' => $url, 'interval' => $interval,
    ]));

    expect($result['valid'])->toBeFalse();
})->with([
    ['not-a-url', '5m'],
    ['ftp://example.com', '5m'],
    ['javascript://example.com/%0Aalert(1)', '5m'],
    ['https://example.com', '0'],
    ['https://example.com', '5minutes'],
    ['https://example.com', ' 5m'],
    ['https://example.com', '5M'],
])->group('AC-laravel-sdk-monitor-definitions-4');

it('rejects invalid URLs and intervals from fluent definitions', function (string $url, string $interval): void {
    $registry = new CheckRegistry;
    $registry->uptime('invalid')->url($url)->every($interval);

    expect($this->validator->validateWithRegistry($this->credentials, $registry)['valid'])->toBeFalse();
})->with([
    ['not-a-url', '5m'],
    ['ftp://example.com', '5m'],
    ['https://example.com', 'never'],
])->group('AC-laravel-sdk-monitor-definitions-4');

it('rejects out-of-range config thresholds and api options', function (string $type, string $field, mixed $value): void {
    $check = ['name' => 'invalid', 'url' => 'https://example.com', 'interval' => '5m', $field => $value];

    expect($this->validator->validate(sdkConfigDefinition($type, $check))['valid'])->toBeFalse();
})->with([
    ['domain_expiry', 'warn_days', 0],
    ['domain_expiry', 'warn_days', 366],
    ['response_time_budget', 'percentile', 0],
    ['response_time_budget', 'percentile', 101],
    ['response_time_budget', 'budget_ms', 0],
    ['response_time_budget', 'budget_ms', 3_600_001],
    ['api', 'expected_status', 99],
    ['api', 'expected_status', 600],
    ['api', 'max_latency_ms', 0],
    ['api', 'max_latency_ms', 3_600_001],
    ['api', 'retry_count', -1],
    ['api', 'retry_count', 11],
])->group('AC-laravel-sdk-monitor-definitions-4');

it('rejects out-of-range fluent thresholds at the local boundary', function (): void {
    $registry = new CheckRegistry;
    $registry->domainExpiry('domain')->url('https://example.com')->warnDays(0);
    $registry->responseTimeBudget('budget')->url('https://example.com')->percentile(101)->budgetMs(0);

    expect($this->validator->validateWithRegistry($this->credentials, $registry)['valid'])->toBeFalse();
    expect(fn () => $registry->api('status')->expectStatus(99))->toThrow(InvalidArgumentException::class);
    expect(fn () => $registry->api('latency')->maxLatency(0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $registry->api('retry')->retries(11))->toThrow(InvalidArgumentException::class);
})->group('AC-laravel-sdk-monitor-definitions-4');

it('rejects invalid canonical assertion kind operator path and operand combinations', function (array $assertion): void {
    $config = sdkConfigDefinition('api', [
        'name' => 'api', 'url' => 'https://example.com', 'interval' => '5m', 'assertions' => [$assertion],
    ]);

    expect($this->validator->validate($config)['valid'])->toBeFalse();
})->with([
    [['kind' => 'unknown', 'operator' => 'equals', 'operand' => 200]],
    [['kind' => 'status', 'operator' => 'less_than', 'operand' => 200]],
    [['kind' => 'status', 'operator' => 'equals', 'operand' => '200']],
    [['kind' => 'latency', 'operator' => 'equals', 'operand' => 100]],
    [['kind' => 'latency', 'operator' => 'less_than_or_equal', 'operand' => -1]],
    [['kind' => 'json_path', 'path' => '$..secret', 'operator' => 'exists']],
    [['kind' => 'json_path', 'path' => '$.id', 'operator' => 'exists', 'operand' => true]],
    [['kind' => 'json_path', 'path' => '$.id', 'operator' => 'equals']],
    [['kind' => 'json_path', 'path' => '$.id', 'operator' => 'type', 'operand' => 'date']],
])->group('AC-laravel-sdk-monitor-definitions-4');

it('accepts and canonicalizes legacy config section and assertion names', function (): void {
    $config = $this->credentials + ['checks' => [
        'uptime_checks' => [['name' => 'up', 'url' => 'https://example.com', 'interval' => '1m']],
        'ssl_checks' => [['name' => 'ssl', 'url' => 'https://example.com', 'interval' => '1d']],
        'api_checks' => [[
            'name' => 'api', 'url' => 'https://example.com/api', 'interval' => '5m',
            'assertions' => [['data_path' => 'ok', 'assertion_type' => 'value_compare', 'comparison_operator' => '=', 'expected_value' => true]],
        ]],
        'link_checks' => [['name' => 'links', 'url' => 'https://example.com', 'interval' => '1d']],
        'open_graph_checks' => [['name' => 'og', 'url' => 'https://example.com', 'interval' => '1d']],
        'domain_expiry_checks' => [['name' => 'domain', 'url' => 'https://example.com', 'interval' => '1d']],
        'response_time_budget_checks' => [['name' => 'budget', 'url' => 'https://example.com', 'interval' => '5m']],
    ]];

    $payload = $this->validator->transformPayload($config);

    expect($this->validator->validate($config)['valid'])->toBeTrue()
        ->and(array_keys($payload))->toBe(['contract_version', 'uptime', 'ssl', 'api', 'dead_links', 'open_graph', 'domain_expiry', 'response_time_budget'])
        ->and($payload['api'][0]['assertions'][0]['operand'])->toBeTrue();
})->group('AC-laravel-sdk-monitor-definitions-5');

it('reports missing credentials and accepts no definitions', function (): void {
    expect($this->validator->validate([])['errors'])->toBe([
        'CHECKYBOT_API_KEY is not configured',
        'CHECKYBOT_PROJECT_ID is not configured',
    ])->and($this->validator->validate($this->credentials)['valid'])->toBeTrue();
});
