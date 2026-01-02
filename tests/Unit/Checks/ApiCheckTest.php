<?php

use MarinSolutions\CheckybotLaravel\Checks\ApiCheck;
use MarinSolutions\CheckybotLaravel\Checks\PendingAssertion;

it('sets name via constructor', function () {
    $check = new ApiCheck('health');

    expect($check->getName())->toBe('health');
});

it('sets url fluently', function () {
    $check = (new ApiCheck('health'))
        ->url('https://example.com/api/health');

    expect($check->getUrl())->toBe('https://example.com/api/health');
});

it('sets headers as array', function () {
    $check = (new ApiCheck('health'))
        ->url('https://example.com/api/health')
        ->headers([
            'Authorization' => 'Bearer token',
            'Accept' => 'application/json',
        ]);

    $array = $check->toArray();

    expect($array['headers'])->toBe([
        'Authorization' => 'Bearer token',
        'Accept' => 'application/json',
    ]);
});

it('adds single header with withHeader', function () {
    $check = (new ApiCheck('health'))
        ->url('https://example.com/api/health')
        ->withHeader('Authorization', 'Bearer token')
        ->withHeader('Accept', 'application/json');

    $array = $check->toArray();

    expect($array['headers'])->toBe([
        'Authorization' => 'Bearer token',
        'Accept' => 'application/json',
    ]);
});

it('adds bearer token with withToken', function () {
    $check = (new ApiCheck('health'))
        ->url('https://example.com/api/health')
        ->withToken('my-secret-token');

    $array = $check->toArray();

    expect($array['headers']['Authorization'])->toBe('Bearer my-secret-token');
});

it('returns pending assertion from expect', function () {
    $check = new ApiCheck('health');
    $pending = $check->expect('status');

    expect($pending)->toBeInstanceOf(PendingAssertion::class);
});

it('adds exists assertion with expectPathExists', function () {
    $check = (new ApiCheck('health'))
        ->url('https://example.com/api/health')
        ->expectPathExists('status');

    $array = $check->toArray();

    expect($array['assertions'])->toHaveCount(1)
        ->and($array['assertions'][0]['data_path'])->toBe('status')
        ->and($array['assertions'][0]['assertion_type'])->toBe('exists');
});

it('adds exists assertion with expect toExist', function () {
    $check = (new ApiCheck('health'))
        ->url('https://example.com/api/health')
        ->expect('status')->toExist();

    $array = $check->toArray();

    expect($array['assertions'][0]['assertion_type'])->toBe('exists');
});

it('adds exists assertion with expect exists alias', function () {
    $check = (new ApiCheck('health'))
        ->url('https://example.com/api/health')
        ->expect('status')->exists();

    $array = $check->toArray();

    expect($array['assertions'][0]['assertion_type'])->toBe('exists');
});

it('adds equality assertion with toEqual', function () {
    $check = (new ApiCheck('health'))
        ->url('https://example.com/api/health')
        ->expect('status')->toEqual('healthy');

    $array = $check->toArray();

    expect($array['assertions'][0]['data_path'])->toBe('status')
        ->and($array['assertions'][0]['assertion_type'])->toBe('comparison')
        ->and($array['assertions'][0]['comparison_operator'])->toBe('==')
        ->and($array['assertions'][0]['expected_value'])->toBe('healthy');
});

it('adds equality assertion with toBe alias', function () {
    $check = (new ApiCheck('health'))
        ->expect('status')->toBe('healthy');

    $array = $check->toArray();

    expect($array['assertions'][0]['comparison_operator'])->toBe('==')
        ->and($array['assertions'][0]['expected_value'])->toBe('healthy');
});

it('adds equality assertion with equals alias', function () {
    $check = (new ApiCheck('health'))
        ->expect('status')->equals('healthy');

    $array = $check->toArray();

    expect($array['assertions'][0]['comparison_operator'])->toBe('==');
});

it('adds not equal assertion with notToEqual', function () {
    $check = (new ApiCheck('health'))
        ->expect('status')->notToEqual('error');

    $array = $check->toArray();

    expect($array['assertions'][0]['comparison_operator'])->toBe('!=')
        ->and($array['assertions'][0]['expected_value'])->toBe('error');
});

it('adds not equal assertion with notToBe alias', function () {
    $check = (new ApiCheck('health'))
        ->expect('status')->notToBe('error');

    $array = $check->toArray();

    expect($array['assertions'][0]['comparison_operator'])->toBe('!=');
});

it('adds greater than assertion', function () {
    $check = (new ApiCheck('health'))
        ->expect('uptime')->toBeGreaterThan(99);

    $array = $check->toArray();

    expect($array['assertions'][0]['comparison_operator'])->toBe('>')
        ->and($array['assertions'][0]['expected_value'])->toBe('99');
});

it('adds greater than or equal assertion', function () {
    $check = (new ApiCheck('health'))
        ->expect('workers')->toBeGreaterThanOrEqual(1);

    $array = $check->toArray();

    expect($array['assertions'][0]['comparison_operator'])->toBe('>=')
        ->and($array['assertions'][0]['expected_value'])->toBe('1');
});

it('adds less than assertion', function () {
    $check = (new ApiCheck('health'))
        ->expect('queue_size')->toBeLessThan(1000);

    $array = $check->toArray();

    expect($array['assertions'][0]['comparison_operator'])->toBe('<')
        ->and($array['assertions'][0]['expected_value'])->toBe('1000');
});

it('adds less than or equal assertion', function () {
    $check = (new ApiCheck('health'))
        ->expect('error_rate')->toBeLessThanOrEqual(0.01);

    $array = $check->toArray();

    expect($array['assertions'][0]['comparison_operator'])->toBe('<=')
        ->and($array['assertions'][0]['expected_value'])->toBe('0.01');
});

it('adds true assertion with toBeTrue', function () {
    $check = (new ApiCheck('health'))
        ->expect('database.connected')->toBeTrue();

    $array = $check->toArray();

    expect($array['assertions'][0]['comparison_operator'])->toBe('==')
        ->and($array['assertions'][0]['expected_value'])->toBe('true');
});

it('adds false assertion with toBeFalse', function () {
    $check = (new ApiCheck('health'))
        ->expect('maintenance_mode')->toBeFalse();

    $array = $check->toArray();

    expect($array['assertions'][0]['comparison_operator'])->toBe('==')
        ->and($array['assertions'][0]['expected_value'])->toBe('false');
});

it('adds type assertion with toBeType', function () {
    $check = (new ApiCheck('health'))
        ->expect('count')->toBeType('integer');

    $array = $check->toArray();

    expect($array['assertions'][0]['assertion_type'])->toBe('type')
        ->and($array['assertions'][0]['expected_type'])->toBe('integer');
});

it('adds string type assertion', function () {
    $check = (new ApiCheck('health'))
        ->expect('message')->toBeString();

    $array = $check->toArray();

    expect($array['assertions'][0]['expected_type'])->toBe('string');
});

it('adds integer type assertion', function () {
    $check = (new ApiCheck('health'))
        ->expect('count')->toBeInteger();

    $array = $check->toArray();

    expect($array['assertions'][0]['expected_type'])->toBe('integer');
});

it('adds int type assertion alias', function () {
    $check = (new ApiCheck('health'))
        ->expect('count')->toBeInt();

    $array = $check->toArray();

    expect($array['assertions'][0]['expected_type'])->toBe('integer');
});

it('adds boolean type assertion', function () {
    $check = (new ApiCheck('health'))
        ->expect('active')->toBeBoolean();

    $array = $check->toArray();

    expect($array['assertions'][0]['expected_type'])->toBe('boolean');
});

it('adds bool type assertion alias', function () {
    $check = (new ApiCheck('health'))
        ->expect('active')->toBeBool();

    $array = $check->toArray();

    expect($array['assertions'][0]['expected_type'])->toBe('boolean');
});

it('adds array type assertion', function () {
    $check = (new ApiCheck('health'))
        ->expect('items')->toBeArray();

    $array = $check->toArray();

    expect($array['assertions'][0]['expected_type'])->toBe('array');
});

it('adds object type assertion', function () {
    $check = (new ApiCheck('health'))
        ->expect('data')->toBeObject();

    $array = $check->toArray();

    expect($array['assertions'][0]['expected_type'])->toBe('object');
});

it('adds regex assertion with toMatch', function () {
    $check = (new ApiCheck('health'))
        ->expect('version')->toMatch('/^v\d+\.\d+\.\d+$/');

    $array = $check->toArray();

    expect($array['assertions'][0]['assertion_type'])->toBe('regex')
        ->and($array['assertions'][0]['regex_pattern'])->toBe('/^v\d+\.\d+\.\d+$/');
});

it('adds regex assertion with toMatchRegex alias', function () {
    $check = (new ApiCheck('health'))
        ->expect('email')->toMatchRegex('/^[a-z]+@example\.com$/');

    $array = $check->toArray();

    expect($array['assertions'][0]['assertion_type'])->toBe('regex');
});

it('chains multiple assertions', function () {
    $check = (new ApiCheck('health'))
        ->url('https://example.com/api/health')
        ->everyFiveMinutes()
        ->expect('status')->toEqual('healthy')
        ->expect('database.connected')->toBeTrue()
        ->expect('queue.size')->toBeLessThan(1000)
        ->expect('workers')->toBeGreaterThanOrEqual(1);

    $array = $check->toArray();

    expect($array['assertions'])->toHaveCount(4)
        ->and($array['assertions'][0]['data_path'])->toBe('status')
        ->and($array['assertions'][1]['data_path'])->toBe('database.connected')
        ->and($array['assertions'][2]['data_path'])->toBe('queue.size')
        ->and($array['assertions'][3]['data_path'])->toBe('workers');
});

it('sets sort_order and is_active on assertions', function () {
    $check = (new ApiCheck('health'))
        ->expect('status')->toExist()
        ->expect('version')->toExist();

    $array = $check->toArray();

    expect($array['assertions'][0]['sort_order'])->toBe(1)
        ->and($array['assertions'][0]['is_active'])->toBeTrue()
        ->and($array['assertions'][1]['sort_order'])->toBe(2)
        ->and($array['assertions'][1]['is_active'])->toBeTrue();
});

it('converts to array without optional fields', function () {
    $check = (new ApiCheck('health'))
        ->url('https://example.com/api/health')
        ->every('5m');

    $array = $check->toArray();

    expect($array)->toBe([
        'name' => 'health',
        'url' => 'https://example.com/api/health',
        'interval' => '5m',
    ]);
});

it('converts to array with all fields', function () {
    $check = (new ApiCheck('health'))
        ->url('https://example.com/api/health')
        ->every('5m')
        ->headers(['Authorization' => 'Bearer token'])
        ->expect('status')->toEqual('healthy');

    $array = $check->toArray();

    expect($array)->toHaveKeys(['name', 'url', 'interval', 'headers', 'assertions'])
        ->and($array['headers'])->toBe(['Authorization' => 'Bearer token'])
        ->and($array['assertions'])->toHaveCount(1);
});
