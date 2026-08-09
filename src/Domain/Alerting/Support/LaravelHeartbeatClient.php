<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\HeartbeatClient;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\HeartbeatResponse;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\HeartbeatTransportException;

final readonly class LaravelHeartbeatClient implements HeartbeatClient
{
    public function __construct(private Factory $http) {}

    public function get(string $url, int $timeoutSeconds, string $userAgent): HeartbeatResponse
    {
        try {
            $response = $this->http
                ->timeout($timeoutSeconds)
                ->connectTimeout($timeoutSeconds)
                ->withUserAgent($userAgent)
                ->get($url);
        } catch (ConnectionException $exception) {
            $message = strtolower($exception->getMessage());

            throw str_contains($message, 'timed out') || str_contains($message, 'timeout')
                ? HeartbeatTransportException::timeout()
                : HeartbeatTransportException::transport();
        }

        return new HeartbeatResponse($response->status());
    }
}
