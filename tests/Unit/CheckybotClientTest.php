<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MarinSolutions\CheckybotLaravel\Exceptions\CheckybotSyncException;
use MarinSolutions\CheckybotLaravel\Http\CheckybotClient;

it('sends sync request to correct endpoint', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'message' => 'Checks synced successfully',
            'summary' => [
                'uptime_checks' => ['created' => 1, 'updated' => 0, 'deleted' => 0],
                'ssl_checks' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
                'api_checks' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
            ],
        ])),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $guzzle = new Client(['handler' => $handlerStack]);

    $client = new CheckybotClient(
        baseUrl: 'https://checkybot.com',
        apiKey: 'test-key',
        projectId: '1',
        client: $guzzle
    );

    $payload = [
        'uptime_checks' => [['name' => 'test', 'url' => 'https://example.com', 'interval' => '5m']],
        'ssl_checks' => [],
        'api_checks' => [],
    ];

    $result = $client->syncChecks($payload);

    expect($result['message'])->toBe('Checks synced successfully')
        ->and($result['summary']['uptime_checks']['created'])->toBe(1);
});

it('throws CheckybotSyncException on http error', function () {
    $mock = new MockHandler([
        new Response(422, [], json_encode([
            'message' => 'The given data was invalid.',
            'errors' => ['uptime_checks.0.url' => ['The url field must be a valid URL.']],
        ])),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $guzzle = new Client(['handler' => $handlerStack]);

    $client = new CheckybotClient(
        baseUrl: 'https://checkybot.com',
        apiKey: 'test-key',
        projectId: '1',
        client: $guzzle
    );

    $client->syncChecks(['uptime_checks' => [], 'ssl_checks' => [], 'api_checks' => []]);
})->throws(CheckybotSyncException::class);

it('throws CheckybotSyncException on network error', function () {
    $mock = new MockHandler([
        new RequestException('Connection timeout', new Request('POST', 'test')),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $guzzle = new Client(['handler' => $handlerStack]);

    $client = new CheckybotClient(
        baseUrl: 'https://checkybot.com',
        apiKey: 'test-key',
        projectId: '1',
        client: $guzzle
    );

    $client->syncChecks(['uptime_checks' => [], 'ssl_checks' => [], 'api_checks' => []]);
})->throws(CheckybotSyncException::class);

it('throws CheckybotSyncException on 401 unauthorized', function () {
    $mock = new MockHandler([
        new Response(401, [], json_encode([
            'message' => 'Unauthenticated.',
        ])),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $guzzle = new Client(['handler' => $handlerStack]);

    $client = new CheckybotClient(
        baseUrl: 'https://checkybot.com',
        apiKey: 'invalid-key',
        projectId: '1',
        client: $guzzle
    );

    $client->syncChecks(['uptime_checks' => [], 'ssl_checks' => [], 'api_checks' => []]);
})->throws(CheckybotSyncException::class);

it('throws CheckybotSyncException on 500 server error', function () {
    $mock = new MockHandler([
        new Response(500, [], json_encode([
            'message' => 'Internal server error',
        ])),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $guzzle = new Client(['handler' => $handlerStack]);

    $client = new CheckybotClient(
        baseUrl: 'https://checkybot.com',
        apiKey: 'test-key',
        projectId: '1',
        client: $guzzle
    );

    $client->syncChecks(['uptime_checks' => [], 'ssl_checks' => [], 'api_checks' => []]);
})->throws(CheckybotSyncException::class);

it('includes validation errors in exception message', function () {
    $mock = new MockHandler([
        new Response(422, [], json_encode([
            'message' => 'The given data was invalid.',
            'errors' => [
                'uptime_checks.0.url' => ['The url field must be a valid URL.'],
                'uptime_checks.0.interval' => ['The interval field is required.'],
            ],
        ])),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $guzzle = new Client(['handler' => $handlerStack]);

    $client = new CheckybotClient(
        baseUrl: 'https://checkybot.com',
        apiKey: 'test-key',
        projectId: '1',
        client: $guzzle
    );

    try {
        $client->syncChecks(['uptime_checks' => [['name' => 'test', 'url' => 'invalid']], 'ssl_checks' => [], 'api_checks' => []]);
        $this->fail('Expected CheckybotSyncException was not thrown');
    } catch (CheckybotSyncException $e) {
        expect($e->getMessage())->toContain('Validation failed')
            ->and($e->getMessage())->toContain('uptime_checks.0.url');
    }
});

it('handles response with updates and deletes in summary', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'message' => 'Checks synced successfully',
            'summary' => [
                'uptime_checks' => ['created' => 2, 'updated' => 3, 'deleted' => 1],
                'ssl_checks' => ['created' => 1, 'updated' => 1, 'deleted' => 0],
                'api_checks' => ['created' => 0, 'updated' => 2, 'deleted' => 2],
            ],
        ])),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $guzzle = new Client(['handler' => $handlerStack]);

    $client = new CheckybotClient(
        baseUrl: 'https://checkybot.com',
        apiKey: 'test-key',
        projectId: '1',
        client: $guzzle
    );

    $result = $client->syncChecks(['uptime_checks' => [], 'ssl_checks' => [], 'api_checks' => []]);

    expect($result['summary']['uptime_checks']['created'])->toBe(2)
        ->and($result['summary']['uptime_checks']['updated'])->toBe(3)
        ->and($result['summary']['uptime_checks']['deleted'])->toBe(1)
        ->and($result['summary']['api_checks']['deleted'])->toBe(2);
});

it('creates client with default guzzle instance when none provided', function () {
    $client = new CheckybotClient(
        baseUrl: 'https://checkybot.com',
        apiKey: 'test-key',
        projectId: '1'
    );

    expect($client)->toBeInstanceOf(CheckybotClient::class);
});
