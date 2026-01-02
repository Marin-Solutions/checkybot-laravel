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

it('handles malformed json response by returning empty array', function () {
    $mock = new MockHandler([
        new Response(200, [], 'not valid json'),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $guzzle = new Client(['handler' => $handlerStack]);

    $client = new CheckybotClient(
        baseUrl: 'https://checkybot.com',
        apiKey: 'test-key',
        projectId: '1',
        client: $guzzle
    );

    // The client should handle malformed JSON gracefully
    // Currently it returns null which fails type check, so this tests the behavior
    $result = $client->syncChecks(['uptime_checks' => [], 'ssl_checks' => [], 'api_checks' => []]);

    // json_decode returns null for invalid JSON, but method expects array
    // This is a known edge case - the API should always return valid JSON
    expect($result)->toBeArray();
});

it('handles empty response body by returning empty array', function () {
    $mock = new MockHandler([
        new Response(200, [], '{}'),
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

    expect($result)->toBeArray();
});

it('handles response without summary key', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'message' => 'Checks synced successfully',
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

    expect($result['message'])->toBe('Checks synced successfully')
        ->and($result)->not->toHaveKey('summary');
});

it('handles 404 not found error', function () {
    $mock = new MockHandler([
        new Response(404, [], json_encode([
            'message' => 'Project not found.',
        ])),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $guzzle = new Client(['handler' => $handlerStack]);

    $client = new CheckybotClient(
        baseUrl: 'https://checkybot.com',
        apiKey: 'test-key',
        projectId: '999',
        client: $guzzle
    );

    $client->syncChecks(['uptime_checks' => [], 'ssl_checks' => [], 'api_checks' => []]);
})->throws(CheckybotSyncException::class);

it('handles rate limit 429 error', function () {
    $mock = new MockHandler([
        new Response(429, [], json_encode([
            'message' => 'Too many requests. Please try again later.',
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

it('preserves exception code from http error', function () {
    $mock = new MockHandler([
        new Response(403, [], json_encode([
            'message' => 'Forbidden',
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
        $client->syncChecks(['uptime_checks' => [], 'ssl_checks' => [], 'api_checks' => []]);
        $this->fail('Expected CheckybotSyncException was not thrown');
    } catch (CheckybotSyncException $e) {
        expect($e->getCode())->toBe(403);
    }
});

it('sends correct payload structure to api', function () {
    $requestBody = null;

    $mock = new MockHandler([
        function ($request) use (&$requestBody) {
            $requestBody = json_decode($request->getBody()->getContents(), true);

            return new Response(200, [], json_encode([
                'message' => 'Success',
                'summary' => [
                    'uptime_checks' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
                    'ssl_checks' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
                    'api_checks' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
                ],
            ]));
        },
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
        'uptime_checks' => [
            ['name' => 'test', 'url' => 'https://example.com', 'interval' => '5m'],
        ],
        'ssl_checks' => [
            ['name' => 'ssl-test', 'url' => 'https://example.com', 'interval' => '1d'],
        ],
        'api_checks' => [],
    ];

    $client->syncChecks($payload);

    expect($requestBody)->toBe($payload);
});

it('sends authorization header with bearer token', function () {
    $authHeader = null;

    $mock = new MockHandler([
        function ($request) use (&$authHeader) {
            $authHeader = $request->getHeader('Authorization')[0] ?? null;

            return new Response(200, [], json_encode(['message' => 'Success', 'summary' => []]));
        },
    ]);

    $handlerStack = HandlerStack::create($mock);
    $guzzle = new Client(['handler' => $handlerStack]);

    $client = new CheckybotClient(
        baseUrl: 'https://checkybot.com',
        apiKey: 'my-secret-api-key',
        projectId: '1',
        client: $guzzle
    );

    $client->syncChecks(['uptime_checks' => [], 'ssl_checks' => [], 'api_checks' => []]);

    expect($authHeader)->toBe('Bearer my-secret-api-key');
});

it('sends request to correct url with project id', function () {
    $requestUri = null;

    $mock = new MockHandler([
        function ($request) use (&$requestUri) {
            $requestUri = $request->getUri()->getPath();

            return new Response(200, [], json_encode(['message' => 'Success', 'summary' => []]));
        },
    ]);

    $handlerStack = HandlerStack::create($mock);
    $guzzle = new Client(['handler' => $handlerStack]);

    $client = new CheckybotClient(
        baseUrl: 'https://checkybot.com',
        apiKey: 'test-key',
        projectId: '42',
        client: $guzzle
    );

    $client->syncChecks(['uptime_checks' => [], 'ssl_checks' => [], 'api_checks' => []]);

    expect($requestUri)->toBe('/api/v1/projects/42/checks/sync');
});
