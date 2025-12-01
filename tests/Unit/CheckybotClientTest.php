<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use MarinSolutions\CheckybotLaravel\Exceptions\CheckybotSyncException;
use MarinSolutions\CheckybotLaravel\Http\CheckybotClient;
use MarinSolutions\CheckybotLaravel\Tests\TestCase;
use Mockery;

class CheckybotClientTest extends TestCase
{
    public function test_sync_checks_successfully(): void
    {
        Log::shouldReceive('info')->twice();

        $mockClient = Mockery::mock(Client::class);
        $mockResponse = new Response(200, [], json_encode([
            'message' => 'Checks synced successfully',
            'summary' => [
                'uptime_checks' => ['created' => 1, 'updated' => 0, 'deleted' => 0],
            ],
        ]));

        $mockClient->shouldReceive('post')
            ->once()
            ->andReturn($mockResponse);

        $client = new CheckybotClient(
            'https://checkybot.com',
            'test-key',
            '1',
            30,
            1,
            100
        );

        // Use reflection to replace the client
        $reflection = new \ReflectionClass($client);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($client, $mockClient);

        $payload = [
            'uptime_checks' => [],
            'ssl_checks' => [],
            'api_checks' => [],
        ];

        $result = $client->syncChecks($payload);

        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('summary', $result);
    }

    public function test_sync_checks_throws_exception_on_error(): void
    {
        Log::shouldReceive('info')->once(); // For "sync started"
        Log::shouldReceive('error')->once(); // For "sync failed"

        $mockClient = Mockery::mock(Client::class);
        $request = new Request('POST', '/api/v1/projects/1/checks/sync');
        $response = new Response(422, [], json_encode([
            'message' => 'Validation failed',
            'errors' => ['uptime_checks.0.url' => ['Invalid URL']],
        ]));

        $exception = new RequestException('Validation failed', $request, $response);

        $mockClient->shouldReceive('post')
            ->once()
            ->andThrow($exception);

        $client = new CheckybotClient(
            'https://checkybot.com',
            'test-key',
            '1',
            30,
            1,
            100
        );

        // Use reflection to replace the client
        $reflection = new \ReflectionClass($client);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($client, $mockClient);

        $payload = [
            'uptime_checks' => [],
            'ssl_checks' => [],
            'api_checks' => [],
        ];

        $this->expectException(CheckybotSyncException::class);
        $client->syncChecks($payload);
    }
}
