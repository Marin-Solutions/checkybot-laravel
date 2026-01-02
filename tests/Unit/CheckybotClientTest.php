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
