<?php

use MarinSolutions\CheckybotLaravel\CheckRegistry;
use MarinSolutions\CheckybotLaravel\Checks\ApiCheck;
use MarinSolutions\CheckybotLaravel\Checks\DomainExpiryCheck;
use MarinSolutions\CheckybotLaravel\Checks\LinkCheck;
use MarinSolutions\CheckybotLaravel\Checks\OpenGraphCheck;
use MarinSolutions\CheckybotLaravel\Checks\ResponseTimeBudgetCheck;
use MarinSolutions\CheckybotLaravel\Checks\SslCheck;
use MarinSolutions\CheckybotLaravel\Checks\UptimeCheck;

beforeEach(function (): void {
    $this->registry = new CheckRegistry;
});

it('creates and retrieves all seven fluent definition types', function (): void {
    expect($this->registry->uptime('uptime'))->toBeInstanceOf(UptimeCheck::class)
        ->and($this->registry->ssl('ssl'))->toBeInstanceOf(SslCheck::class)
        ->and($this->registry->api('api'))->toBeInstanceOf(ApiCheck::class)
        ->and($this->registry->links('links'))->toBeInstanceOf(LinkCheck::class)
        ->and($this->registry->openGraph('og'))->toBeInstanceOf(OpenGraphCheck::class)
        ->and($this->registry->domainExpiry('domain'))->toBeInstanceOf(DomainExpiryCheck::class)
        ->and($this->registry->responseTimeBudget('budget'))->toBeInstanceOf(ResponseTimeBudgetCheck::class)
        ->and($this->registry->count())->toBe(7)
        ->and($this->registry->getDomainExpiryChecks())->toHaveCount(1)
        ->and($this->registry->getResponseTimeBudgetChecks())->toHaveCount(1);
})->group('AC-laravel-sdk-monitor-definitions-1', 'AC-laravel-sdk-monitor-definitions-5');

it('serializes exactly the canonical versioned seven-array envelope', function (): void {
    $this->registry->uptime('uptime')->url('https://example.com')->maxRedirects(5)->withHeader('Accept', 'text/html')->everySecond();
    $this->registry->ssl('ssl')->url('https://example.com')->daily();
    $this->registry->api('api')->url('https://example.com/api')->expectStatus(200)->maxLatency(700)->retries(2)->expect('ok')->toBeTrue();
    $this->registry->links('links')->url('https://example.com')->maxDepth(2)->exclude(['/admin'])->daily();
    $this->registry->openGraph('og')->url('https://example.com')->requireTag('og:title')->daily();
    $this->registry->domainExpiry('domain')->url('https://example.com')->daily();
    $this->registry->responseTimeBudget('budget')->url('https://example.com')->everyFiveMinutes();

    $payload = $this->registry->toArray();

    expect(array_keys($payload))->toBe([
        'contract_version', 'uptime', 'ssl', 'api', 'dead_links', 'open_graph', 'domain_expiry', 'response_time_budget',
    ])->and($payload['contract_version'])->toBe('check-sync.v1')
        ->and($payload['uptime'][0]['max_redirects'])->toBe(5)
        ->and($payload['api'][0]['assertions'])->toHaveCount(3)
        ->and($payload['dead_links'][0]['exclude_paths'])->toBe(['/admin'])
        ->and($payload['open_graph'][0]['required_tags'])->toBe(['og:title'])
        ->and($payload['domain_expiry'][0]['warn_days'])->toBe(30)
        ->and($payload['response_time_budget'][0])->toMatchArray(['percentile' => 95, 'budget_ms' => 2000]);
})->group('AC-laravel-sdk-monitor-definitions-1', 'AC-laravel-sdk-monitor-definitions-2', 'AC-laravel-sdk-monitor-definitions-5');

it('flushes every type and preserves chainable legacy count and getters', function (): void {
    $this->registry->uptime('one');
    $this->registry->ssl('two');
    $this->registry->api('three');
    $this->registry->links('four');
    $this->registry->openGraph('five');
    $this->registry->domainExpiry('six');
    $this->registry->responseTimeBudget('seven');

    expect($this->registry->flush())->toBe($this->registry)
        ->and($this->registry->count())->toBe(0)
        ->and($this->registry->getUptimeChecks())->toBeEmpty()
        ->and($this->registry->getSslChecks())->toBeEmpty()
        ->and($this->registry->getApiChecks())->toBeEmpty()
        ->and($this->registry->getLinkChecks())->toBeEmpty()
        ->and($this->registry->getOpenGraphChecks())->toBeEmpty()
        ->and($this->registry->getDomainExpiryChecks())->toBeEmpty()
        ->and($this->registry->getResponseTimeBudgetChecks())->toBeEmpty();
})->group('AC-laravel-sdk-monitor-definitions-5');
