<?php

use MarinSolutions\CheckybotLaravel\CheckRegistry;
use MarinSolutions\CheckybotLaravel\Checks\ApiCheck;
use MarinSolutions\CheckybotLaravel\Checks\SslCheck;
use MarinSolutions\CheckybotLaravel\Checks\UptimeCheck;
use MarinSolutions\CheckybotLaravel\Facades\Checkybot;

beforeEach(function () {
    // Flush registry before each test
    app(CheckRegistry::class)->flush();
});

it('creates uptime check via facade', function () {
    $check = Checkybot::uptime('homepage');

    expect($check)->toBeInstanceOf(UptimeCheck::class)
        ->and($check->getName())->toBe('homepage');
});

it('creates ssl check via facade', function () {
    $check = Checkybot::ssl('main-ssl');

    expect($check)->toBeInstanceOf(SslCheck::class)
        ->and($check->getName())->toBe('main-ssl');
});

it('creates api check via facade', function () {
    $check = Checkybot::api('health');

    expect($check)->toBeInstanceOf(ApiCheck::class)
        ->and($check->getName())->toBe('health');
});

it('registers checks in the singleton registry', function () {
    Checkybot::uptime('homepage')
        ->url('https://example.com')
        ->everyFiveMinutes();

    Checkybot::ssl('main-ssl')
        ->url('https://example.com')
        ->daily();

    Checkybot::api('health')
        ->url('https://example.com/api/health')
        ->everyMinute();

    expect(Checkybot::count())->toBe(3)
        ->and(Checkybot::getUptimeChecks())->toHaveCount(1)
        ->and(Checkybot::getSslChecks())->toHaveCount(1)
        ->and(Checkybot::getApiChecks())->toHaveCount(1);
});

it('flushes all checks via facade', function () {
    Checkybot::uptime('homepage');
    Checkybot::ssl('main-ssl');
    Checkybot::api('health');

    Checkybot::flush();

    expect(Checkybot::count())->toBe(0);
});

it('converts to array via facade', function () {
    Checkybot::uptime('homepage')
        ->url('https://example.com')
        ->everyFiveMinutes();

    $payload = Checkybot::toArray();

    expect($payload)->toHaveKeys(['uptime_checks', 'ssl_checks', 'api_checks'])
        ->and($payload['uptime_checks'])->toHaveCount(1)
        ->and($payload['uptime_checks'][0]['name'])->toBe('homepage');
});

it('supports full fluent chain via facade', function () {
    Checkybot::api('health')
        ->url('https://example.com/api/health')
        ->everyFiveMinutes()
        ->withToken('secret-token')
        ->expect('status')->toEqual('healthy')
        ->expect('database.connected')->toBeTrue()
        ->expect('queue.size')->toBeLessThan(1000);

    $payload = Checkybot::toArray();

    expect($payload['api_checks'])->toHaveCount(1)
        ->and($payload['api_checks'][0]['assertions'])->toHaveCount(3);
});
