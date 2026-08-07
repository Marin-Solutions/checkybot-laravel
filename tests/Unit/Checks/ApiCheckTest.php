<?php

use MarinSolutions\CheckybotLaravel\Checks\ApiCheck;
use MarinSolutions\CheckybotLaravel\Checks\PendingAssertion;

it('keeps status latency retry and ordered response assertion metadata deterministic', function (): void {
    $check = (new ApiCheck('health'))
        ->url('https://example.com/api/health')
        ->everyFiveMinutes()
        ->expectStatus(204)
        ->maxLatency(750)
        ->retries(0)
        ->expect('status')->toEqual('healthy')
        ->expect('enabled')->toBeTrue()
        ->expect('count')->toEqual(12)
        ->expect('ratio')->toEqual(0.5)
        ->expect('optional')->toEqual(null);

    expect($check->toArray())->toBe([
        'name' => 'health',
        'url' => 'https://example.com/api/health',
        'interval' => '5m',
        'expected_status' => 204,
        'max_latency_ms' => 750,
        'retry_count' => 0,
        'assertions' => [
            ['kind' => 'status', 'operator' => 'equals', 'operand' => 204, 'sort_order' => 1, 'is_active' => true],
            ['kind' => 'latency', 'operator' => 'less_than_or_equal', 'operand' => 750, 'sort_order' => 2, 'is_active' => true],
            ['kind' => 'json_path', 'operator' => 'equals', 'path' => 'status', 'operand' => 'healthy', 'sort_order' => 3, 'is_active' => true],
            ['kind' => 'json_path', 'operator' => 'equals', 'path' => 'enabled', 'operand' => true, 'sort_order' => 4, 'is_active' => true],
            ['kind' => 'json_path', 'operator' => 'equals', 'path' => 'count', 'operand' => 12, 'sort_order' => 5, 'is_active' => true],
            ['kind' => 'json_path', 'operator' => 'equals', 'path' => 'ratio', 'operand' => 0.5, 'sort_order' => 6, 'is_active' => true],
            ['kind' => 'json_path', 'operator' => 'equals', 'path' => 'optional', 'operand' => null, 'sort_order' => 7, 'is_active' => true],
        ],
    ]);
})->group('AC-laravel-sdk-monitor-definitions-2');

it('replaces singular status and latency metadata without destabilizing order', function (): void {
    $array = (new ApiCheck('health'))
        ->expectStatus(200)
        ->maxLatency(1000)
        ->expectStatus(201)
        ->maxLatency(800)
        ->toArray();

    expect($array['assertions'])->toBe([
        ['kind' => 'status', 'operator' => 'equals', 'operand' => 201, 'sort_order' => 1, 'is_active' => true],
        ['kind' => 'latency', 'operator' => 'less_than_or_equal', 'operand' => 800, 'sort_order' => 2, 'is_active' => true],
    ]);
})->group('AC-laravel-sdk-monitor-definitions-2');

it('retains every existing response assertion alias and scalar operand type', function (): void {
    $check = (new ApiCheck('aliases'))
        ->expectPathExists('present')
        ->expect('exists')->exists()
        ->expect('equal')->toBe(1)
        ->expect('equals')->equals('yes')
        ->expect('not')->notToBe(false)
        ->expect('gt')->toBeGreaterThan(1)
        ->expect('gte')->toBeGreaterThanOrEqual(2)
        ->expect('lt')->toBeLessThan(3)
        ->expect('lte')->toBeLessThanOrEqual(4)
        ->expect('false')->toBeFalse()
        ->expect('type')->toBeType('number')
        ->expect('string')->toBeString()
        ->expect('integer')->toBeInt()
        ->expect('boolean')->toBeBool()
        ->expect('array')->toBeArray()
        ->expect('object')->toBeObject()
        ->expect('regex')->toMatchRegex('/ok/');

    $assertions = $check->toArray()['assertions'];

    expect($assertions)->toHaveCount(17)
        ->and(array_column($assertions, 'sort_order'))->toBe(range(1, 17))
        ->and(array_unique(array_column($assertions, 'is_active')))->toBe([true])
        ->and($assertions[2]['operand'])->toBeInt()
        ->and($assertions[4]['operand'])->toBeBool()
        ->and($assertions[9]['operand'])->toBeFalse();
})->group('AC-laravel-sdk-monitor-definitions-5');

it('returns a pending assertion and omits optional fields by default', function (): void {
    $check = (new ApiCheck('health'))->url('https://example.com')->every('5m');

    expect($check->expect('status'))->toBeInstanceOf(PendingAssertion::class)
        ->and($check->toArray())->toBe([
            'name' => 'health',
            'url' => 'https://example.com',
            'interval' => '5m',
        ]);
});

it('retains header and token wire plaintext while masking safe and debug representations', function (): void {
    $check = (new ApiCheck('secure'))
        ->headers(['Cookie' => 'session=secret'])
        ->withToken('token-secret')
        ->withHeader('X-Custom', 'custom-secret');

    expect($check->toArray()['headers'])->toBe([
        'Cookie' => 'session=secret',
        'Authorization' => 'Bearer token-secret',
        'X-Custom' => 'custom-secret',
    ])->and($check->toSafeArray()['headers'])->toBe([
        'Cookie' => '[REDACTED]',
        'Authorization' => '[REDACTED]',
        'X-Custom' => '[REDACTED]',
    ]);

    ob_start();
    var_dump($check);
    $debug = (string) ob_get_clean();
    expect($debug)->not->toContain('session=secret')
        ->not->toContain('token-secret')
        ->not->toContain('custom-secret');
})->group('AC-laravel-sdk-monitor-definitions-3');

it('rejects invalid status latency and retry builder values before serialization', function (): void {
    expect(fn () => (new ApiCheck('status'))->expectStatus(99))->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new ApiCheck('latency'))->maxLatency(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new ApiCheck('retry'))->retries(11))->toThrow(InvalidArgumentException::class);
});
