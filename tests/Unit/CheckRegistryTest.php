<?php

use MarinSolutions\CheckybotLaravel\CheckRegistry;
use MarinSolutions\CheckybotLaravel\Checks\ApiCheck;
use MarinSolutions\CheckybotLaravel\Checks\LinkCheck;
use MarinSolutions\CheckybotLaravel\Checks\OpenGraphCheck;
use MarinSolutions\CheckybotLaravel\Checks\SslCheck;
use MarinSolutions\CheckybotLaravel\Checks\UptimeCheck;

beforeEach(function () {
    $this->registry = new CheckRegistry;
});

it('creates uptime check and returns builder', function () {
    $check = $this->registry->uptime('homepage');

    expect($check)->toBeInstanceOf(UptimeCheck::class)
        ->and($check->getName())->toBe('homepage');
});

it('creates ssl check and returns builder', function () {
    $check = $this->registry->ssl('main-ssl');

    expect($check)->toBeInstanceOf(SslCheck::class)
        ->and($check->getName())->toBe('main-ssl');
});

it('creates api check and returns builder', function () {
    $check = $this->registry->api('health');

    expect($check)->toBeInstanceOf(ApiCheck::class)
        ->and($check->getName())->toBe('health');
});

it('creates link check and returns builder', function () {
    $check = $this->registry->links('homepage-links');

    expect($check)->toBeInstanceOf(LinkCheck::class)
        ->and($check->getName())->toBe('homepage-links');
});

it('creates open graph check and returns builder', function () {
    $check = $this->registry->openGraph('homepage-og');

    expect($check)->toBeInstanceOf(OpenGraphCheck::class)
        ->and($check->getName())->toBe('homepage-og');
});

it('stores multiple checks of same type', function () {
    $this->registry->uptime('homepage');
    $this->registry->uptime('api-server');
    $this->registry->uptime('blog');

    expect($this->registry->getUptimeChecks())->toHaveCount(3);
});

it('stores checks of different types separately', function () {
    $this->registry->uptime('homepage');
    $this->registry->ssl('main-ssl');
    $this->registry->api('health');
    $this->registry->links('homepage-links');
    $this->registry->openGraph('homepage-og');

    expect($this->registry->getUptimeChecks())->toHaveCount(1)
        ->and($this->registry->getSslChecks())->toHaveCount(1)
        ->and($this->registry->getApiChecks())->toHaveCount(1)
        ->and($this->registry->getLinkChecks())->toHaveCount(1)
        ->and($this->registry->getOpenGraphChecks())->toHaveCount(1);
});

it('counts total checks across all types', function () {
    $this->registry->uptime('homepage');
    $this->registry->uptime('api-server');
    $this->registry->ssl('main-ssl');
    $this->registry->api('health');
    $this->registry->api('database-health');
    $this->registry->links('docs-links');
    $this->registry->openGraph('blog-og');

    expect($this->registry->count())->toBe(7);
});

it('flushes all checks', function () {
    $this->registry->uptime('homepage');
    $this->registry->ssl('main-ssl');
    $this->registry->api('health');
    $this->registry->links('homepage-links');
    $this->registry->openGraph('homepage-og');

    $this->registry->flush();

    expect($this->registry->count())->toBe(0)
        ->and($this->registry->getUptimeChecks())->toBeEmpty()
        ->and($this->registry->getSslChecks())->toBeEmpty()
        ->and($this->registry->getApiChecks())->toBeEmpty()
        ->and($this->registry->getLinkChecks())->toBeEmpty()
        ->and($this->registry->getOpenGraphChecks())->toBeEmpty();
});

it('converts to array format for api payload', function () {
    $this->registry->uptime('homepage')
        ->url('https://example.com')
        ->every('5m');

    $this->registry->ssl('main-ssl')
        ->url('https://example.com')
        ->daily();

    $this->registry->api('health')
        ->url('https://example.com/api/health')
        ->everyMinute();

    $this->registry->links('homepage-links')
        ->url('https://example.com')
        ->daily();

    $this->registry->openGraph('homepage-og')
        ->url('https://example.com')
        ->daily();

    $payload = $this->registry->toArray();

    expect($payload)->toHaveKeys(['uptime_checks', 'ssl_checks', 'api_checks', 'link_checks', 'open_graph_checks'])
        ->and($payload['uptime_checks'])->toHaveCount(1)
        ->and($payload['ssl_checks'])->toHaveCount(1)
        ->and($payload['api_checks'])->toHaveCount(1)
        ->and($payload['link_checks'])->toHaveCount(1)
        ->and($payload['open_graph_checks'])->toHaveCount(1)
        ->and($payload['uptime_checks'][0]['name'])->toBe('homepage')
        ->and($payload['uptime_checks'][0]['url'])->toBe('https://example.com')
        ->and($payload['uptime_checks'][0]['interval'])->toBe('5m');
});
