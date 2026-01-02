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

// Second interval helpers
it('provides everySecond helper', function () {
    $check = (new UptimeCheck('homepage'))->everySecond();

    expect($check->getInterval())->toBe('1s');
});

it('provides everyTwoSeconds helper', function () {
    $check = (new UptimeCheck('homepage'))->everyTwoSeconds();

    expect($check->getInterval())->toBe('2s');
});

it('provides everyFiveSeconds helper', function () {
    $check = (new UptimeCheck('homepage'))->everyFiveSeconds();

    expect($check->getInterval())->toBe('5s');
});

it('provides everyTenSeconds helper', function () {
    $check = (new UptimeCheck('homepage'))->everyTenSeconds();

    expect($check->getInterval())->toBe('10s');
});

it('provides everyFifteenSeconds helper', function () {
    $check = (new UptimeCheck('homepage'))->everyFifteenSeconds();

    expect($check->getInterval())->toBe('15s');
});

it('provides everyTwentySeconds helper', function () {
    $check = (new UptimeCheck('homepage'))->everyTwentySeconds();

    expect($check->getInterval())->toBe('20s');
});

it('provides everyThirtySeconds helper', function () {
    $check = (new UptimeCheck('homepage'))->everyThirtySeconds();

    expect($check->getInterval())->toBe('30s');
});

// Minute interval helpers
it('provides everyMinute helper', function () {
    $check = (new UptimeCheck('homepage'))->everyMinute();

    expect($check->getInterval())->toBe('1m');
});

it('provides everyTwoMinutes helper', function () {
    $check = (new UptimeCheck('homepage'))->everyTwoMinutes();

    expect($check->getInterval())->toBe('2m');
});

it('provides everyThreeMinutes helper', function () {
    $check = (new UptimeCheck('homepage'))->everyThreeMinutes();

    expect($check->getInterval())->toBe('3m');
});

it('provides everyFourMinutes helper', function () {
    $check = (new UptimeCheck('homepage'))->everyFourMinutes();

    expect($check->getInterval())->toBe('4m');
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

// Hour interval helpers
it('provides hourly helper', function () {
    $check = (new UptimeCheck('homepage'))->hourly();

    expect($check->getInterval())->toBe('1h');
});

it('provides everyTwoHours helper', function () {
    $check = (new UptimeCheck('homepage'))->everyTwoHours();

    expect($check->getInterval())->toBe('2h');
});

it('provides everyThreeHours helper', function () {
    $check = (new UptimeCheck('homepage'))->everyThreeHours();

    expect($check->getInterval())->toBe('3h');
});

it('provides everyFourHours helper', function () {
    $check = (new UptimeCheck('homepage'))->everyFourHours();

    expect($check->getInterval())->toBe('4h');
});

it('provides everySixHours helper', function () {
    $check = (new UptimeCheck('homepage'))->everySixHours();

    expect($check->getInterval())->toBe('6h');
});

it('provides twiceDaily helper', function () {
    $check = (new UptimeCheck('homepage'))->twiceDaily();

    expect($check->getInterval())->toBe('12h');
});

// Day interval helpers
it('provides daily helper', function () {
    $check = (new UptimeCheck('homepage'))->daily();

    expect($check->getInterval())->toBe('1d');
});

it('provides weekly helper', function () {
    $check = (new UptimeCheck('homepage'))->weekly();

    expect($check->getInterval())->toBe('7d');
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
