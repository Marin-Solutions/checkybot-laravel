<?php

use MarinSolutions\CheckybotLaravel\ConfigValidator;

beforeEach(function () {
    $this->validator = new ConfigValidator();
});

it('returns valid when api_key and project_id are present', function () {
    $config = [
        'api_key' => 'test-key',
        'project_id' => '1',
        'checks' => ['uptime' => [], 'ssl' => [], 'api' => []],
    ];

    $result = $this->validator->validate($config);

    expect($result['valid'])->toBeTrue()
        ->and($result['errors'])->toBeEmpty();
});

it('returns error when api_key is missing', function () {
    $config = [
        'api_key' => null,
        'project_id' => '1',
        'checks' => ['uptime' => [], 'ssl' => [], 'api' => []],
    ];

    $result = $this->validator->validate($config);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->toContain('CHECKYBOT_API_KEY is not configured');
});

it('returns error when project_id is missing', function () {
    $config = [
        'api_key' => 'test-key',
        'project_id' => null,
        'checks' => ['uptime' => [], 'ssl' => [], 'api' => []],
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
        ],
    ];

    $payload = $this->validator->transformPayload($config);

    expect($payload)->toHaveKeys(['uptime_checks', 'ssl_checks', 'api_checks'])
        ->and($payload['uptime_checks'])->toHaveCount(1)
        ->and($payload['ssl_checks'])->toHaveCount(1)
        ->and($payload['api_checks'])->toBeEmpty();
});
