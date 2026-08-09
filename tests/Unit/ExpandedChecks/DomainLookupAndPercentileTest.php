<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Data\DomainName;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Support\NearestRankPercentile;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Support\RdapDomainExpiryLookup;

it('rejects non-host domain input', function (string $input): void {
    expect(fn () => new DomainName($input))->toThrow(InvalidArgumentException::class);
})->with(['https://example.com', 'example.com/path', 'user@example.com', 'example.com:443']);

it('classifies bounded RDAP adapter failures without leaking response content', function (Response $response, string $expected, bool $retryable): void {
    $adapter = new RdapDomainExpiryLookup(new Client([
        'handler' => HandlerStack::create(new MockHandler([$response])),
    ]), 1);
    $result = $adapter->lookup(new DomainName('example.com'));

    expect($result->successful)->toBeFalse()
        ->and($result->failureCode)->toBe($expected)
        ->and($result->retryable)->toBe($retryable)
        ->and($result->expiresAt)->toBeNull()
        ->and($result->failureCode)->not->toContain('secret');
})->with([
    'malformed' => [new Response(200, [], '{"secret":"token"'), 'domain_lookup_malformed', true],
    'no expiry' => [new Response(200, [], '{"events":[]}'), 'domain_lookup_no_expiry', true],
    'terminal not found' => [new Response(404, [], 'secret diagnostic'), 'domain_lookup_not_found', false],
]);

it('uses nearest rank rather than interpolation', function (): void {
    $values = [...array_fill(0, 18, 100.0), 9000.0];
    expect((new NearestRankPercentile)->calculate($values, 0.95))->toBe(9000.0);
})->group('AC-agent-v2-expanded-monitors-10', 'AC-agent-v2-expanded-monitors-12');
