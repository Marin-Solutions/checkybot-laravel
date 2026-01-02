<?php

use MarinSolutions\CheckybotLaravel\Checks\SslCheck;

it('sets name via constructor', function () {
    $check = new SslCheck('main-ssl');

    expect($check->getName())->toBe('main-ssl');
});

it('sets url fluently', function () {
    $check = (new SslCheck('main-ssl'))
        ->url('https://example.com');

    expect($check->getUrl())->toBe('https://example.com');
});

it('sets interval with every method', function () {
    $check = (new SslCheck('main-ssl'))
        ->url('https://example.com')
        ->every('1d');

    expect($check->getInterval())->toBe('1d');
});

it('provides daily helper for ssl checks', function () {
    $check = (new SslCheck('main-ssl'))
        ->url('https://example.com')
        ->daily();

    expect($check->getInterval())->toBe('1d');
});

it('converts to array', function () {
    $check = (new SslCheck('main-ssl'))
        ->url('https://example.com')
        ->daily();

    $array = $check->toArray();

    expect($array)->toBe([
        'name' => 'main-ssl',
        'url' => 'https://example.com',
        'interval' => '1d',
    ]);
});

it('chains methods fluently', function () {
    $check = (new SslCheck('api-ssl'))
        ->url('https://api.example.com')
        ->every('12h');

    expect($check)->toBeInstanceOf(SslCheck::class)
        ->and($check->getName())->toBe('api-ssl')
        ->and($check->getUrl())->toBe('https://api.example.com')
        ->and($check->getInterval())->toBe('12h');
});
