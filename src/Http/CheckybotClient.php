<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use MarinSolutions\CheckybotLaravel\Exceptions\CheckybotSyncException;
use MarinSolutions\CheckybotLaravel\Support\Constants;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP client for Checkybot API communication
 */
class CheckybotClient
{
    protected Client $client;

    public function __construct(
        protected string $baseUrl,
        protected string $apiKey,
        protected string $projectId,
        protected int $timeout = Constants::DEFAULT_TIMEOUT,
        protected int $retryTimes = Constants::DEFAULT_RETRY_TIMES,
        protected int $retryDelay = Constants::DEFAULT_RETRY_DELAY
    ) {
        $this->client = new Client([
            'base_uri' => rtrim($baseUrl, '/'),
            'timeout' => $timeout,
            'headers' => $this->buildHeaders(),
        ]);
    }

    /**
     * Build HTTP headers for API requests
     *
     * @return array<string, string>
     */
    protected function buildHeaders(): array
    {
        return [
            Constants::HEADER_ACCEPT => Constants::HEADER_ACCEPT_JSON,
            Constants::HEADER_X_API_KEY => $this->apiKey,
            Constants::HEADER_AUTHORIZATION => Constants::HEADER_AUTHORIZATION_PREFIX . $this->apiKey,
        ];
    }

    /**
     * Sync monitoring checks to Checkybot
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws CheckybotSyncException
     */
    public function syncChecks(array $payload): array
    {
        $url = sprintf(Constants::API_ENDPOINT_SYNC, $this->projectId);
        $totalChecks = $this->countTotalChecks($payload);

        $this->logSyncStarted($totalChecks);

        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->retryTimes) {
            try {
                $response = $this->client->post($url, ['json' => $payload]);
                $body = $this->parseResponse($response);

                $this->logSyncSuccess($body);

                return $body;
            } catch (GuzzleException $e) {
                $lastException = $e;
                $attempt++;

                if ($attempt < $this->retryTimes) {
                    $this->waitBeforeRetry();
                    continue;
                }

                $this->logSyncFailed($e, $attempt);
                throw $this->createSyncException($e);
            }
        }

        // This should never be reached, but PHP requires it
        throw new CheckybotSyncException(
            Constants::ERROR_SYNC_FAILED,
            0,
            $lastException
        );
    }

    /**
     * Count total checks in payload
     *
     * @param  array<string, mixed>  $payload
     */
    protected function countTotalChecks(array $payload): int
    {
        return count($payload['uptime_checks'] ?? [])
            + count($payload['ssl_checks'] ?? [])
            + count($payload['api_checks'] ?? []);
    }

    /**
     * Parse JSON response body
     *
     * @return array<string, mixed>
     */
    protected function parseResponse(ResponseInterface $response): array
    {
        $contents = $response->getBody()->getContents();
        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Wait before retrying request
     */
    protected function waitBeforeRetry(): void
    {
        usleep($this->retryDelay * 1000); // Convert milliseconds to microseconds
    }

    /**
     * Create sync exception from Guzzle exception
     *
     * @throws CheckybotSyncException
     */
    protected function createSyncException(GuzzleException $e): CheckybotSyncException
    {
        $errorMessage = $this->parseErrorMessage($e);

        return new CheckybotSyncException($errorMessage, $e->getCode(), $e);
    }

    /**
     * Parse error message from Guzzle exception
     */
    protected function parseErrorMessage(GuzzleException $e): string
    {
        if (!($e instanceof RequestException) || !$e->hasResponse()) {
            return $e->getMessage();
        }

        $response = $e->getResponse();
        if ($response === null) {
            return $e->getMessage();
        }

        $body = json_decode($response->getBody()->getContents(), true);

        if (!is_array($body)) {
            return $e->getMessage();
        }

        if (isset($body['errors'])) {
            return 'Validation failed: ' . json_encode($body['errors'], JSON_UNESCAPED_SLASHES);
        }

        return $body['message'] ?? 'Unknown error occurred';
    }

    /**
     * Log sync started event
     */
    protected function logSyncStarted(int $totalChecks): void
    {
        Log::info(Constants::LOG_SYNC_STARTED, [
            'project_id' => $this->projectId,
            'total_checks' => $totalChecks,
        ]);
    }

    /**
     * Log sync success event
     *
     * @param  array<string, mixed>  $body
     */
    protected function logSyncSuccess(array $body): void
    {
        Log::info(Constants::LOG_SYNC_SUCCESS, [
            'project_id' => $this->projectId,
            'summary' => $body['summary'] ?? null,
        ]);
    }

    /**
     * Log sync failed event
     */
    protected function logSyncFailed(GuzzleException $e, int $attempt): void
    {
        Log::error(Constants::LOG_SYNC_FAILED, [
            'project_id' => $this->projectId,
            'error' => $this->parseErrorMessage($e),
            'status_code' => $e->getCode(),
            'attempt' => $attempt,
        ]);
    }
}
