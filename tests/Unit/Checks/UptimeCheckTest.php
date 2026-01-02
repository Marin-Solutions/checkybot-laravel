<?php

use MarinSolutions\CheckybotLaravel\Checks\UptimeCheck;

it('sets name via constructor', function () {
    $check = new UptimeCheck('homepage');

    expect($check->getName())->toBe('homepage');
});

it('sets url fluently', function () {
    $check = (new UptimeCheck('homepage'))
        ->url('https://example.com');

    expect($check->getUrl())->toBe('https://example.com');
});

it('sets interval with every method', function () {
    $check = (new UptimeCheck('homepage'))
        ->url('https://example.com')
        ->every('10m');

    expect($check->getInterval())->toBe('10m');
});

it('sets interval with interval alias', function () {
    $check = (new UptimeCheck('homepage'))
        ->interval('15m');

    expect($check->getInterval())->toBe('15m');
});

it('sets max redirects', function () {
    $check = (new UptimeCheck('homepage'))
        ->url('https://example.com')
        ->maxRedirects(5)
        ->every('5m');

    $array = $check->toArray();

    expect($array['max_redirects'])->toBe(5);
});

it('sets max redirects with followRedirects alias', function () {
    $check = (new UptimeCheck('homepage'))
        ->url('https://example.com')
        ->followRedirects(3);

    $array = $check->toArray();

    expect($array['max_redirects'])->toBe(3);
});

it('provides everyMinute helper', function () {
    $check = (new UptimeCheck('homepage'))->everyMinute();

    expect($check->getInterval())->toBe('1m');
});

it('provides everyFiveMinutes helper', function () {
    $check = (new UptimeCheck('homepage'))->everyFiveMinutes();

    expect($check->getInterval())->toBe('5m');
});

it('provides everyTenMinutes helper', function () {
    $check = (new UptimeCheck('homepage'))->everyTenMinutes();

    expect($check->getInterval())->toBe('10m');
});

it('provides everyFifteenMinutes helper', function () {
    $check = (new UptimeCheck('homepage'))->everyFifteenMinutes();

    expect($check->getInterval())->toBe('15m');
});

it('provides everyThirtyMinutes helper', function () {
    $check = (new UptimeCheck('homepage'))->everyThirtyMinutes();

    expect($check->getInterval())->toBe('30m');
});

it('provides hourly helper', function () {
    $check = (new UptimeCheck('homepage'))->hourly();

    expect($check->getInterval())->toBe('1h');
});

it('provides daily helper', function () {
    $check = (new UptimeCheck('homepage'))->daily();

    expect($check->getInterval())->toBe('1d');
});

it('converts to array without optional fields', function () {
    $check = (new UptimeCheck('homepage'))
        ->url('https://example.com')
        ->every('5m');

    $array = $check->toArray();

    expect($array)->toBe([
        'name' => 'homepage',
        'url' => 'https://example.com',
        'interval' => '5m',
    ]);
});

it('converts to array with all fields', function () {
    $check = (new UptimeCheck('homepage'))
        ->url('https://example.com')
        ->every('5m')
        ->maxRedirects(10);

    $array = $check->toArray();

    expect($array)->toBe([
        'name' => 'homepage',
        'url' => 'https://example.com',
        'interval' => '5m',
        'max_redirects' => 10,
    ]);
});

it('chains methods fluently', function () {
    $check = (new UptimeCheck('homepage'))
        ->url('https://example.com')
        ->maxRedirects(5)
        ->everyFiveMinutes();

    expect($check)->toBeInstanceOf(UptimeCheck::class)
        ->and($check->getName())->toBe('homepage')
        ->and($check->getUrl())->toBe('https://example.com')
        ->and($check->getInterval())->toBe('5m')
        ->and($check->toArray()['max_redirects'])->toBe(5);
});
