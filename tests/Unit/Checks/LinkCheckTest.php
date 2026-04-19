<?php

use MarinSolutions\CheckybotLaravel\Checks\LinkCheck;

it('sets name via constructor', function () {
    $check = new LinkCheck('homepage-links');

    expect($check->getName())->toBe('homepage-links');
});

it('sets url fluently', function () {
    $check = (new LinkCheck('homepage-links'))
        ->url('https://example.com');

    expect($check->getUrl())->toBe('https://example.com');
});

it('sets interval with every method', function () {
    $check = (new LinkCheck('homepage-links'))
        ->url('https://example.com')
        ->every('1d');

    expect($check->getInterval())->toBe('1d');
});

it('sets max depth', function () {
    $check = (new LinkCheck('homepage-links'))
        ->url('https://example.com')
        ->maxDepth(2)
        ->every('1d');

    $array = $check->toArray();

    expect($array['max_depth'])->toBe(2);
});

it('sets exclude paths', function () {
    $check = (new LinkCheck('homepage-links'))
        ->url('https://example.com')
        ->exclude(['/admin/*', '/logout'])
        ->every('1d');

    $array = $check->toArray();

    expect($array['exclude_paths'])->toBe(['/admin/*', '/logout']);
});

it('sets headers as array', function () {
    $check = (new LinkCheck('homepage-links'))
        ->url('https://example.com')
        ->headers([
            'Authorization' => 'Bearer token',
            'Accept' => 'text/html',
        ]);

    $array = $check->toArray();

    expect($array['headers'])->toBe([
        'Authorization' => 'Bearer token',
        'Accept' => 'text/html',
    ]);
});

it('adds single header with withHeader', function () {
    $check = (new LinkCheck('homepage-links'))
        ->url('https://example.com')
        ->withHeader('Authorization', 'Bearer token');

    $array = $check->toArray();

    expect($array['headers']['Authorization'])->toBe('Bearer token');
});

it('adds bearer token with withToken', function () {
    $check = (new LinkCheck('homepage-links'))
        ->url('https://example.com')
        ->withToken('my-secret-token');

    $array = $check->toArray();

    expect($array['headers']['Authorization'])->toBe('Bearer my-secret-token');
});

it('converts to array without optional fields', function () {
    $check = (new LinkCheck('homepage-links'))
        ->url('https://example.com')
        ->every('1d');

    $array = $check->toArray();

    expect($array)->toBe([
        'name' => 'homepage-links',
        'url' => 'https://example.com',
        'interval' => '1d',
    ]);
});

it('converts to array with all fields', function () {
    $check = (new LinkCheck('homepage-links'))
        ->url('https://example.com')
        ->every('1d')
        ->maxDepth(2)
        ->exclude(['/admin/*'])
        ->headers(['Authorization' => 'Bearer token']);

    $array = $check->toArray();

    expect($array)->toHaveKeys(['name', 'url', 'interval', 'max_depth', 'exclude_paths', 'headers'])
        ->and($array['max_depth'])->toBe(2)
        ->and($array['exclude_paths'])->toBe(['/admin/*'])
        ->and($array['headers'])->toBe(['Authorization' => 'Bearer token']);
});

it('chains methods fluently', function () {
    $check = (new LinkCheck('docs-links'))
        ->url('https://example.com/docs')
        ->maxDepth(3)
        ->exclude(['/api/*'])
        ->withToken('secret')
        ->every('12h');

    expect($check)->toBeInstanceOf(LinkCheck::class)
        ->and($check->getName())->toBe('docs-links')
        ->and($check->getUrl())->toBe('https://example.com/docs')
        ->and($check->getInterval())->toBe('12h')
        ->and($check->toArray()['max_depth'])->toBe(3)
        ->and($check->toArray()['exclude_paths'])->toBe(['/api/*']);
});

it('provides daily helper', function () {
    $check = (new LinkCheck('homepage-links'))
        ->url('https://example.com')
        ->daily();

    expect($check->getInterval())->toBe('1d');
});
