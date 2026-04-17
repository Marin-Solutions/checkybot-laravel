<?php

use MarinSolutions\CheckybotLaravel\Checks\OpenGraphCheck;

it('sets name via constructor', function () {
    $check = new OpenGraphCheck('homepage-og');

    expect($check->getName())->toBe('homepage-og');
});

it('sets url fluently', function () {
    $check = (new OpenGraphCheck('homepage-og'))
        ->url('https://example.com');

    expect($check->getUrl())->toBe('https://example.com');
});

it('sets interval with every method', function () {
    $check = (new OpenGraphCheck('homepage-og'))
        ->url('https://example.com')
        ->every('1d');

    expect($check->getInterval())->toBe('1d');
});

it('sets required tags as array', function () {
    $check = (new OpenGraphCheck('homepage-og'))
        ->url('https://example.com')
        ->requireTags(['og:title', 'og:description', 'og:image']);

    $array = $check->toArray();

    expect($array['required_tags'])->toBe(['og:title', 'og:description', 'og:image']);
});

it('adds single required tag with requireTag', function () {
    $check = (new OpenGraphCheck('homepage-og'))
        ->url('https://example.com')
        ->requireTag('og:title')
        ->requireTag('og:image');

    $array = $check->toArray();

    expect($array['required_tags'])->toBe(['og:title', 'og:image']);
});

it('sets headers as array', function () {
    $check = (new OpenGraphCheck('homepage-og'))
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
    $check = (new OpenGraphCheck('homepage-og'))
        ->url('https://example.com')
        ->withHeader('Authorization', 'Bearer token');

    $array = $check->toArray();

    expect($array['headers']['Authorization'])->toBe('Bearer token');
});

it('adds bearer token with withToken', function () {
    $check = (new OpenGraphCheck('homepage-og'))
        ->url('https://example.com')
        ->withToken('my-secret-token');

    $array = $check->toArray();

    expect($array['headers']['Authorization'])->toBe('Bearer my-secret-token');
});

it('converts to array without optional fields', function () {
    $check = (new OpenGraphCheck('homepage-og'))
        ->url('https://example.com')
        ->every('1d');

    $array = $check->toArray();

    expect($array)->toBe([
        'name' => 'homepage-og',
        'url' => 'https://example.com',
        'interval' => '1d',
    ]);
});

it('converts to array with all fields', function () {
    $check = (new OpenGraphCheck('homepage-og'))
        ->url('https://example.com')
        ->every('1d')
        ->requireTags(['og:title', 'og:description'])
        ->headers(['Authorization' => 'Bearer token']);

    $array = $check->toArray();

    expect($array)->toHaveKeys(['name', 'url', 'interval', 'required_tags', 'headers'])
        ->and($array['required_tags'])->toBe(['og:title', 'og:description'])
        ->and($array['headers'])->toBe(['Authorization' => 'Bearer token']);
});

it('chains methods fluently', function () {
    $check = (new OpenGraphCheck('blog-og'))
        ->url('https://example.com/blog')
        ->requireTags(['og:title', 'og:image'])
        ->withToken('secret')
        ->every('12h');

    expect($check)->toBeInstanceOf(OpenGraphCheck::class)
        ->and($check->getName())->toBe('blog-og')
        ->and($check->getUrl())->toBe('https://example.com/blog')
        ->and($check->getInterval())->toBe('12h')
        ->and($check->toArray()['required_tags'])->toBe(['og:title', 'og:image']);
});

it('provides daily helper', function () {
    $check = (new OpenGraphCheck('homepage-og'))
        ->url('https://example.com')
        ->daily();

    expect($check->getInterval())->toBe('1d');
});
