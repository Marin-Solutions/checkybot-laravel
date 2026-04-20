<?php

use MarinSolutions\CheckybotLaravel\CheckRegistry;
use MarinSolutions\CheckybotLaravel\ConfigValidator;

beforeEach(function () {
    $this->validator = new ConfigValidator;
});

it('returns valid when api_key and project_id are present', function () {
    $config = [
        'api_key' => 'test-key',
        'project_id' => '1',
        'checks' => ['uptime' => [], 'ssl' => [], 'api' => [], 'dead_links' => [], 'open_graph' => []],
    ];

    $result = $this->validator->validate($config);

    expect($result['valid'])->toBeTrue()
        ->and($result['errors'])->toBeEmpty();
});

it('returns error when api_key is missing', function () {
    $config = [
        'api_key' => null,
        'project_id' => '1',
        'checks' => ['uptime' => [], 'ssl' => [], 'api' => [], 'dead_links' => [], 'open_graph' => []],
    ];

    $result = $this->validator->validate($config);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->toContain('CHECKYBOT_API_KEY is not configured');
});

it('returns error when project_id is missing', function () {
    $config = [
        'api_key' => 'test-key',
        'project_id' => null,
        'checks' => ['uptime' => [], 'ssl' => [], 'api' => [], 'dead_links' => [], 'open_graph' => []],
    ];

    $result = $this->validator->validate($config);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->toContain('CHECKYBOT_PROJECT_ID is not configured');
});

it('returns error for duplicate uptime check names', function () {
    $config = [
        'api_key' => 'test-key',
        'project_id' => '1',
        'checks' => [
            'uptime' => [
                ['name' => 'homepage', 'url' => 'https://example.com', 'interval' => '5m'],
                ['name' => 'homepage', 'url' => 'https://example2.com', 'interval' => '5m'],
            ],
            'ssl' => [],
            'api' => [],
            'dead_links' => [],
            'open_graph' => [],
        ],
    ];

    $result = $this->validator->validate($config);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'][0])->toContain('Duplicate uptime check names');
});

it('transforms payload correctly', function () {
    $config = [
        'checks' => [
            'uptime' => [['name' => 'test', 'url' => 'https://example.com', 'interval' => '5m']],
            'ssl' => [['name' => 'ssl-test', 'url' => 'https://example.com', 'interval' => '1d']],
            'api' => [],
            'dead_links' => [['name' => 'links-test', 'url' => 'https://example.com', 'interval' => '1d']],
            'open_graph' => [['name' => 'og-test', 'url' => 'https://example.com', 'interval' => '1d']],
        ],
    ];

    $payload = $this->validator->transformPayload($config);

    expect($payload)->toHaveKeys(['uptime_checks', 'ssl_checks', 'api_checks', 'link_checks', 'open_graph_checks'])
        ->and($payload['uptime_checks'])->toHaveCount(1)
        ->and($payload['ssl_checks'])->toHaveCount(1)
        ->and($payload['link_checks'])->toHaveCount(1)
        ->and($payload['open_graph_checks'])->toHaveCount(1)
        ->and($payload['api_checks'])->toBeEmpty();
});

it('returns error for duplicate ssl check names', function () {
    $config = [
        'api_key' => 'test-key',
        'project_id' => '1',
        'checks' => [
            'uptime' => [],
            'ssl' => [
                ['name' => 'main-ssl', 'url' => 'https://example.com', 'interval' => '1d'],
                ['name' => 'main-ssl', 'url' => 'https://example2.com', 'interval' => '1d'],
            ],
            'api' => [],
            'dead_links' => [],
            'open_graph' => [],
        ],
    ];

    $result = $this->validator->validate($config);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'][0])->toContain('Duplicate ssl check names');
});

it('returns error for duplicate api check names', function () {
    $config = [
        'api_key' => 'test-key',
        'project_id' => '1',
        'checks' => [
            'uptime' => [],
            'ssl' => [],
            'api' => [
                ['name' => 'health', 'url' => 'https://example.com/health', 'interval' => '5m'],
                ['name' => 'health', 'url' => 'https://example.com/api/health', 'interval' => '5m'],
            ],
            'dead_links' => [],
            'open_graph' => [],
        ],
    ];

    $result = $this->validator->validate($config);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'][0])->toContain('Duplicate api check names');
});

it('returns error for duplicate dead_links check names', function () {
    $config = [
        'api_key' => 'test-key',
        'project_id' => '1',
        'checks' => [
            'uptime' => [],
            'ssl' => [],
            'api' => [],
            'dead_links' => [
                ['name' => 'homepage-links', 'url' => 'https://example.com', 'interval' => '1d'],
                ['name' => 'homepage-links', 'url' => 'https://example2.com', 'interval' => '1d'],
            ],
            'open_graph' => [],
        ],
    ];

    $result = $this->validator->validate($config);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'][0])->toContain('Duplicate dead_links check names');
});

it('returns error for duplicate open_graph check names', function () {
    $config = [
        'api_key' => 'test-key',
        'project_id' => '1',
        'checks' => [
            'uptime' => [],
            'ssl' => [],
            'api' => [],
            'dead_links' => [],
            'open_graph' => [
                ['name' => 'homepage-og', 'url' => 'https://example.com', 'interval' => '1d'],
                ['name' => 'homepage-og', 'url' => 'https://example2.com', 'interval' => '1d'],
            ],
        ],
    ];

    $result = $this->validator->validate($config);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'][0])->toContain('Duplicate open_graph check names');
});

it('returns multiple errors when both api_key and project_id are missing', function () {
    $config = [
        'api_key' => null,
        'project_id' => null,
        'checks' => ['uptime' => [], 'ssl' => [], 'api' => [], 'dead_links' => [], 'open_graph' => []],
    ];

    $result = $this->validator->validate($config);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->toHaveCount(2)
        ->and($result['errors'])->toContain('CHECKYBOT_API_KEY is not configured')
        ->and($result['errors'])->toContain('CHECKYBOT_PROJECT_ID is not configured');
});

it('returns valid with empty checks arrays', function () {
    $config = [
        'api_key' => 'test-key',
        'project_id' => '1',
        'checks' => ['uptime' => [], 'ssl' => [], 'api' => [], 'dead_links' => [], 'open_graph' => []],
    ];

    $result = $this->validator->validate($config);

    expect($result['valid'])->toBeTrue()
        ->and($result['errors'])->toBeEmpty();
});

it('handles missing checks key gracefully', function () {
    $config = [
        'api_key' => 'test-key',
        'project_id' => '1',
    ];

    $result = $this->validator->validate($config);

    expect($result['valid'])->toBeTrue();
});

it('transforms payload with api checks including assertions', function () {
    $config = [
        'checks' => [
            'uptime' => [],
            'ssl' => [],
            'api' => [
                [
                    'name' => 'health-check',
                    'url' => 'https://example.com/api/health',
                    'interval' => '5m',
                    'headers' => ['Accept' => 'application/json'],
                    'assertions' => [
                        ['data_path' => 'status', 'assertion_type' => 'exists'],
                        ['data_path' => 'status', 'assertion_type' => 'value_compare', 'comparison_operator' => '=', 'expected_value' => 'healthy'],
                    ],
                ],
            ],
            'dead_links' => [],
            'open_graph' => [],
        ],
    ];

    $payload = $this->validator->transformPayload($config);

    expect($payload['api_checks'])->toHaveCount(1)
        ->and($payload['api_checks'][0]['assertions'])->toHaveCount(2)
        ->and($payload['api_checks'][0]['headers'])->toHaveKey('Accept');
});

it('returns error for invalid url in config check', function () {
    $config = [
        'api_key' => 'test-key',
        'project_id' => '1',
        'checks' => [
            'uptime' => [
                ['name' => 'homepage', 'url' => 'not-a-url', 'interval' => '5m'],
            ],
            'ssl' => [],
            'api' => [],
            'dead_links' => [],
            'open_graph' => [],
        ],
    ];

    $result = $this->validator->validate($config);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->toContain("Check 'homepage' has an invalid URL: not-a-url");
});

it('returns error for invalid interval in config check', function () {
    $config = [
        'api_key' => 'test-key',
        'project_id' => '1',
        'checks' => [
            'uptime' => [
                ['name' => 'homepage', 'url' => 'https://example.com', 'interval' => 'invalid'],
            ],
            'ssl' => [],
            'api' => [],
            'dead_links' => [],
            'open_graph' => [],
        ],
    ];

    $result = $this->validator->validate($config);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->toContain("Check 'homepage' has an invalid interval: invalid");
});

it('returns error for missing url in config check', function () {
    $config = [
        'api_key' => 'test-key',
        'project_id' => '1',
        'checks' => [
            'uptime' => [
                ['name' => 'homepage', 'url' => '', 'interval' => '5m'],
            ],
            'ssl' => [],
            'api' => [],
            'dead_links' => [],
            'open_graph' => [],
        ],
    ];

    $result = $this->validator->validate($config);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->toContain("Check 'homepage' is missing a URL");
});

it('returns error for missing interval in config check', function () {
    $config = [
        'api_key' => 'test-key',
        'project_id' => '1',
        'checks' => [
            'uptime' => [
                ['name' => 'homepage', 'url' => 'https://example.com', 'interval' => ''],
            ],
            'ssl' => [],
            'api' => [],
            'dead_links' => [],
            'open_graph' => [],
        ],
    ];

    $result = $this->validator->validate($config);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->toContain("Check 'homepage' is missing an interval");
});

it('validates registry checks for missing url', function () {
    $registry = new CheckRegistry;
    $registry->uptime('homepage')->every('5m');

    $result = $this->validator->validateWithRegistry([
        'api_key' => 'test-key',
        'project_id' => '1',
    ], $registry);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->toContain("Check 'homepage' is missing a URL");
});

it('validates registry checks for invalid url', function () {
    $registry = new CheckRegistry;
    $registry->uptime('homepage')->url('not-a-url')->every('5m');

    $result = $this->validator->validateWithRegistry([
        'api_key' => 'test-key',
        'project_id' => '1',
    ], $registry);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->toContain("Check 'homepage' has an invalid URL: not-a-url");
});

it('validates registry checks for invalid interval', function () {
    $registry = new CheckRegistry;
    $registry->uptime('homepage')->url('https://example.com')->every('invalid');

    $result = $this->validator->validateWithRegistry([
        'api_key' => 'test-key',
        'project_id' => '1',
    ], $registry);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->toContain("Check 'homepage' has an invalid interval: invalid");
});

it('accepts valid intervals', function () {
    $config = [
        'api_key' => 'test-key',
        'project_id' => '1',
        'checks' => [
            'uptime' => [
                ['name' => 's', 'url' => 'https://example.com', 'interval' => '1s'],
                ['name' => 'm', 'url' => 'https://example.com', 'interval' => '5m'],
                ['name' => 'h', 'url' => 'https://example.com', 'interval' => '1h'],
                ['name' => 'd', 'url' => 'https://example.com', 'interval' => '7d'],
            ],
            'ssl' => [],
            'api' => [],
            'dead_links' => [],
            'open_graph' => [],
        ],
    ];

    $result = $this->validator->validate($config);

    expect($result['valid'])->toBeTrue();
});

it('validates duplicate link check names in registry', function () {
    $registry = new CheckRegistry;
    $registry->links('homepage-links')->url('https://example.com')->daily();
    $registry->links('homepage-links')->url('https://example2.com')->daily();

    $result = $this->validator->validateWithRegistry([
        'api_key' => 'test-key',
        'project_id' => '1',
    ], $registry);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'][0])->toContain('Duplicate link check names');
});

it('validates duplicate open graph check names in registry', function () {
    $registry = new CheckRegistry;
    $registry->openGraph('homepage-og')->url('https://example.com')->daily();
    $registry->openGraph('homepage-og')->url('https://example2.com')->daily();

    $result = $this->validator->validateWithRegistry([
        'api_key' => 'test-key',
        'project_id' => '1',
    ], $registry);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'][0])->toContain('Duplicate open_graph check names');
});

it('validates all registry check fields across types', function () {
    $registry = new CheckRegistry;
    $registry->uptime('bad-uptime')->url('bad-url')->every('bad');
    $registry->ssl('bad-ssl')->url('')->every('1d');
    $registry->links('bad-links')->url('https://example.com')->every('');

    $result = $this->validator->validateWithRegistry([
        'api_key' => 'test-key',
        'project_id' => '1',
    ], $registry);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->toContain("Check 'bad-uptime' has an invalid URL: bad-url")
        ->and($result['errors'])->toContain("Check 'bad-uptime' has an invalid interval: bad")
        ->and($result['errors'])->toContain("Check 'bad-ssl' is missing a URL")
        ->and($result['errors'])->toContain("Check 'bad-links' is missing an interval");
});

it('validates v1 config requires checkybot url and project identifier', function () {
    $config = [
        'api_key' => 'test-key',
        'base_url' => null,
        'project_identifier' => null,
        'project_id' => null,
        'checks' => [],
    ];

    $result = $this->validator->validate($config);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->toContain('CHECKYBOT_URL is not configured')
        ->and($result['errors'])->toContain('CHECKYBOT_PROJECT_IDENTIFIER is not configured');
});

it('builds v1 sync payload with metadata and merged headers', function () {
    $config = [
        'api_key' => 'test-key',
        'base_url' => 'https://checkybot.test',
        'project_identifier' => 'marin-solutions/checkybot-laravel',
        'environment' => 'production',
        'default_headers' => [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer default-token',
        ],
        'checks' => [
            [
                'type' => 'api',
                'name' => 'scrappa-health',
                'method' => 'POST',
                'path' => '/api/health',
                'interval' => '5m',
                'headers' => [
                    'Authorization' => 'Bearer per-check-token',
                    'X-Scrappa-Key' => 'scrappa-secret',
                ],
                'expected_status' => 202,
                'timeout' => 12,
                'required_json_paths' => ['status', 'database.connected'],
                'body_assertions' => [
                    ['path' => 'status', 'operator' => 'equals', 'value' => 'healthy'],
                ],
            ],
            [
                'type' => 'ssl',
                'name' => 'app-ssl',
                'url' => 'https://example.com',
                'interval' => '1d',
            ],
        ],
    ];

    $payload = $this->validator->buildSyncPayload($config);

    expect($payload)->toMatchArray([
        'project_identifier' => 'marin-solutions/checkybot-laravel',
        'environment' => 'production',
        'checks' => [
            [
                'type' => 'api',
                'name' => 'scrappa-health',
                'method' => 'POST',
                'path' => '/api/health',
                'interval' => '5m',
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer per-check-token',
                    'X-Scrappa-Key' => 'scrappa-secret',
                ],
                'expected_status' => 202,
                'timeout' => 12,
                'required_json_paths' => ['status', 'database.connected'],
                'body_assertions' => [
                    ['path' => 'status', 'operator' => 'equals', 'value' => 'healthy'],
                ],
            ],
            [
                'type' => 'ssl',
                'name' => 'app-ssl',
                'url' => 'https://example.com',
                'interval' => '1d',
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer default-token',
                ],
            ],
        ],
    ]);
});
