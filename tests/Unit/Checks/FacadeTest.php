<?php

use MarinSolutions\CheckybotLaravel\CheckRegistry;
use MarinSolutions\CheckybotLaravel\Checks\ApiCheck;
use MarinSolutions\CheckybotLaravel\Checks\DomainExpiryCheck;
use MarinSolutions\CheckybotLaravel\Checks\LinkCheck;
use MarinSolutions\CheckybotLaravel\Checks\OpenGraphCheck;
use MarinSolutions\CheckybotLaravel\Checks\ResponseTimeBudgetCheck;
use MarinSolutions\CheckybotLaravel\Checks\SslCheck;
use MarinSolutions\CheckybotLaravel\Checks\UptimeCheck;
use MarinSolutions\CheckybotLaravel\Facades\Checkybot;

beforeEach(function (): void {
    app(CheckRegistry::class)->flush();
});

it('retains old facade factories and adds v1 definition factories', function (): void {
    expect(Checkybot::uptime('uptime'))->toBeInstanceOf(UptimeCheck::class)
        ->and(Checkybot::ssl('ssl'))->toBeInstanceOf(SslCheck::class)
        ->and(Checkybot::api('api'))->toBeInstanceOf(ApiCheck::class)
        ->and(Checkybot::links('links'))->toBeInstanceOf(LinkCheck::class)
        ->and(Checkybot::openGraph('og'))->toBeInstanceOf(OpenGraphCheck::class)
        ->and(Checkybot::domainExpiry('domain'))->toBeInstanceOf(DomainExpiryCheck::class)
        ->and(Checkybot::responseTimeBudget('budget'))->toBeInstanceOf(ResponseTimeBudgetCheck::class)
        ->and(Checkybot::count())->toBe(7);
})->group('AC-laravel-sdk-monitor-definitions-1', 'AC-laravel-sdk-monitor-definitions-5');

it('serializes through the facade and flushes the singleton registry', function (): void {
    Checkybot::api('health')
        ->url('https://example.com/api')
        ->everyFiveMinutes()
        ->expect('status')->toEqual('healthy')
        ->expect('active')->toBeTrue();

    expect(Checkybot::toArray()['api'][0]['assertions'])->toHaveCount(2)
        ->and(Checkybot::getApiChecks())->toHaveCount(1)
        ->and(Checkybot::flush())->toBeInstanceOf(CheckRegistry::class)
        ->and(Checkybot::count())->toBe(0);
})->group('AC-laravel-sdk-monitor-definitions-5');
