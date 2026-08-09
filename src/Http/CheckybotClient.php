<?php

namespace MarinSolutions\CheckybotLaravel\Http;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use MarinSolutions\CheckybotLaravel\Exceptions\CheckybotSyncException;
use Psr\Http\Message\ResponseInterface;

class CheckybotClient
{
    private const COMPONENT_KEY_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/';

    private const IDEMPOTENCY_KEY_PATTERN = '/\A[a-f0-9]{64}\z/i';

    private const MAX_MESSAGE_LENGTH = 500;

    private const MAX_METRICS = 20;

    private const MAX_METRIC_VALUE = 1_000_000_000;

    /**
     * @var array<int, string>
     */
    private const ALLOWED_METRIC_KEYS = [
        'active',
        'configured_pairs',
        'count',
        'coverage_percent',
        'due',
        'duration_ms',
        'error_count',
        'failed',
        'failure_count',
        'failure_streak',
        'healthy',
        'latency_ms',
        'missing_pairs',
        'oldest_overdue_age_minutes',
        'overdue',
        'stale_claims',
        'success_count',
        'total',
        'unique_keywords',
        'warning',
    ];

    protected Client $client;

    public function __construct(
        protected string $baseUrl,
        protected string $apiKey,
        protected string $projectId,
        protected int $timeout = 30,
        protected int $retryTimes = 3,
        protected int $retryDelay = 1000,
        ?Client $client = null
    ) {
        $this->assertSecureBaseUrl($baseUrl);

        $this->client = $client ?? new Client([
            'base_uri' => rtrim($baseUrl, '/'),
            'timeout' => $timeout,
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$apiKey,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws CheckybotSyncException
     */
    public function syncChecks(array $payload): array
    {
        $url = '/api/v1/projects/'.rawurlencode($this->projectId).'/checks/sync';

        try {
            $response = $this->postSync($url, $payload);
            $statusCode = $response->getStatusCode();
            $body = $this->decodeResponseBody($response);

            if (! in_array($statusCode, [200, 202], true)) {
                throw new CheckybotSyncException(
                    $this->redactSyncMessage($this->formatErrorMessage($body), $payload),
                    $statusCode
                );
            }

            Log::info('Checkybot sync successful', [
                'project_id' => $this->projectId,
                'summary' => $body['summary'] ?? null,
            ]);

            return $body;
        } catch (GuzzleException $e) {
            $errorMessage = $this->redactSyncMessage($this->parseErrorMessage($e), $payload);

            Log::error('Checkybot sync failed', [
                'project_id' => $this->projectId,
                'error' => $errorMessage,
                'status_code' => $e->getCode(),
            ]);

            // Do not retain the transport exception: request objects may contain
            // authorization or JSON body plaintext in their debug representation.
            throw new CheckybotSyncException($errorMessage, (int) $e->getCode());
        }
    }

    private function assertSecureBaseUrl(string $baseUrl): void
    {
        $valid = filter_var($baseUrl, FILTER_VALIDATE_URL) !== false;
        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
        $host = strtolower(trim((string) parse_url($baseUrl, PHP_URL_HOST), '[]'));

        if ($valid && $scheme === 'https') {
            return;
        }

        $harnessEnvironment = false;
        try {
            $harnessEnvironment = app()->environment(['testing', 'harness']);
        } catch (\Throwable) {
            $harnessEnvironment = in_array((string) getenv('APP_ENV'), ['testing', 'harness'], true);
        }

        if ($valid && $scheme === 'http' && $harnessEnvironment && in_array($host, ['127.0.0.1', '::1', 'localhost'], true)) {
            return;
        }

        throw new InvalidArgumentException('Checkybot sync requires an HTTPS base URL; HTTP is restricted to the loopback test harness.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postSync(string $url, array $payload): ResponseInterface
    {
        // Declaration sync is a single authoritative replacement request. Do not
        // replay it after an ambiguous network outcome; component-status reports
        // have a separate idempotency key and bounded retry path below.
        return $this->client->post($url, [
            'json' => $payload,
            'allow_redirects' => false,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$this->apiKey,
            ],
        ]);
    }

    /**
     * Report the current status of an already-declared aggregate component.
     *
     * Declaration sync and runtime status reporting are deliberately separate
     * API operations. This method sends only the bounded status contract.
     *
     * @param  array<string, int|float|bool>  $metrics
     * @return array<string, mixed>
     *
     * @throws CheckybotSyncException
     * @throws InvalidArgumentException
     */
    public function reportComponentStatus(
        string $componentKey,
        string $status,
        DateTimeInterface|string $observedAt,
        string $message,
        array $metrics,
        ?string $idempotencyKey = null
    ): array {
        $this->validateComponentKey($componentKey);
        $this->validateStatus($status);
        $observedAt = $this->normalizeObservedAt($observedAt);
        $this->validateMessage($message);
        $this->validateMetrics($metrics);
        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);

        $url = "/api/v1/projects/{$this->projectId}/components/".rawurlencode($componentKey).'/status';
        $payload = [
            'status' => $status,
            'observed_at' => $observedAt,
            'message' => $message,
            'metrics' => $metrics,
        ];

        try {
            $response = $this->postComponentStatus($url, $payload, $idempotencyKey);
            $statusCode = $response->getStatusCode();
            $body = $this->decodeResponseBody($response);

            if ($statusCode >= 400) {
                Log::error('Checkybot component status failed', [
                    'project_id' => $this->projectId,
                    'component_key' => $componentKey,
                    'status_code' => $statusCode,
                ]);

                throw new CheckybotSyncException(
                    'Checkybot component status request failed.',
                    $statusCode
                );
            }

            Log::info('Checkybot component status reported', [
                'project_id' => $this->projectId,
                'component_key' => $componentKey,
                'status' => $status,
                'metric_count' => count($metrics),
            ]);

            return $body;
        } catch (GuzzleException $e) {
            Log::error('Checkybot component status failed', [
                'project_id' => $this->projectId,
                'component_key' => $componentKey,
                'status_code' => $e->getCode(),
            ]);

            throw new CheckybotSyncException(
                'Checkybot component status request failed.',
                (int) $e->getCode()
            );
        }
    }

    private function validateComponentKey(string $componentKey): void
    {
        if (preg_match(self::COMPONENT_KEY_PATTERN, $componentKey) !== 1) {
            throw new InvalidArgumentException(
                'Component key must start with a letter or number and contain only letters, numbers, dots, underscores, or hyphens (maximum 64 characters).'
            );
        }
    }

    private function validateStatus(string $status): void
    {
        if (! in_array($status, ['healthy', 'warning', 'failure'], true)) {
            throw new InvalidArgumentException('Component status must be healthy, warning, or failure.');
        }
    }

    private function normalizeIdempotencyKey(?string $idempotencyKey): string
    {
        $idempotencyKey ??= bin2hex(random_bytes(32));

        if (preg_match(self::IDEMPOTENCY_KEY_PATTERN, $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Idempotency key must be exactly 64 hexadecimal characters.');
        }

        return strtolower($idempotencyKey);
    }

    private function normalizeObservedAt(DateTimeInterface|string $observedAt): string
    {
        if ($observedAt instanceof DateTimeInterface) {
            return (new DateTimeImmutable($observedAt->format(DateTimeInterface::RFC3339)))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format(DateTimeInterface::RFC3339);
        }

        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/', $observedAt) !== 1) {
            throw new InvalidArgumentException('Observed at must be an RFC3339 timestamp with a timezone.');
        }

        try {
            $parsed = new DateTimeImmutable($observedAt);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException('Observed at must be a valid RFC3339 timestamp.', previous: $exception);
        }

        $errors = DateTimeImmutable::getLastErrors();

        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new InvalidArgumentException('Observed at must be a valid RFC3339 timestamp.');
        }

        return $parsed
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(DateTimeInterface::RFC3339);
    }

    private function validateMessage(string $message): void
    {
        if ($message === '' || strlen($message) > self::MAX_MESSAGE_LENGTH || preg_match('/\A[^\x00-\x1F\x7F]+\z/u', $message) !== 1) {
            throw new InvalidArgumentException('Component status message must be 1–500 characters without control characters.');
        }
    }

    /**
     * @param  array<mixed>  $metrics
     */
    private function validateMetrics(array $metrics): void
    {
        if (count($metrics) > self::MAX_METRICS) {
            throw new InvalidArgumentException('Component status metrics may contain at most 20 values.');
        }

        foreach ($metrics as $key => $value) {
            if (! is_string($key) || ! in_array($key, self::ALLOWED_METRIC_KEYS, true)) {
                throw new InvalidArgumentException("Metric key [{$key}] is not allowed.");
            }

            if (! is_int($value) && ! is_float($value) && ! is_bool($value)) {
                throw new InvalidArgumentException("Metric [{$key}] must be an integer, finite number, or boolean.");
            }

            if (is_float($value) && ! is_finite($value)) {
                throw new InvalidArgumentException("Metric [{$key}] must be finite.");
            }

            if (! is_bool($value) && ($value < 0 || $value > self::MAX_METRIC_VALUE)) {
                throw new InvalidArgumentException("Metric [{$key}] must be between 0 and ".self::MAX_METRIC_VALUE.'.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postComponentStatus(string $url, array $payload, string $idempotencyKey): ResponseInterface
    {
        $maxRetries = max(0, $this->retryTimes);

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->client->post($url, [
                    'json' => $payload,
                    'headers' => [
                        'Accept' => 'application/json',
                        'Authorization' => 'Bearer '.$this->apiKey,
                        'Idempotency-Key' => $idempotencyKey,
                    ],
                ]);
            } catch (GuzzleException $exception) {
                if (! $this->shouldRetryException($exception) || $attempt >= $maxRetries) {
                    throw $exception;
                }

                $this->waitBeforeRetry();

                continue;
            }

            if (! $this->isRetryableStatus($response->getStatusCode()) || $attempt >= $maxRetries) {
                return $response;
            }

            $this->waitBeforeRetry();
        }
    }

    private function shouldRetryException(GuzzleException $exception): bool
    {
        if ($exception instanceof RequestException && $exception->hasResponse()) {
            return $this->isRetryableStatus($exception->getResponse()->getStatusCode());
        }

        return true;
    }

    private function isRetryableStatus(int $statusCode): bool
    {
        return $statusCode === 408 || $statusCode === 429 || $statusCode >= 500;
    }

    private function waitBeforeRetry(): void
    {
        if ($this->retryDelay > 0) {
            usleep($this->retryDelay * 1000);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponseBody(ResponseInterface $response): array
    {
        $decoded = json_decode($response->getBody()->getContents(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $payload */
    private function redactSyncMessage(string $message, array $payload): string
    {
        $secrets = [$this->apiKey, 'Bearer '.$this->apiKey];
        $collect = function (mixed $value, ?string $key = null) use (&$collect, &$secrets): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $childKey => $child) {
                if ($key === 'headers' && is_string($child) && $child !== '') {
                    $secrets[] = $child;
                    if (preg_match('/^Bearer\\s+(.+)$/i', $child, $match) === 1) {
                        $secrets[] = $match[1];
                    }
                    if (preg_match_all('/(?:^|;\\s*)[^=;]+=(?<value>[^;]+)/', $child, $matches) > 0) {
                        array_push($secrets, ...$matches['value']);
                    }
                }
                $collect($child, is_string($childKey) ? $childKey : null);
            }
        };
        $collect($payload);

        foreach (array_unique($secrets) as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '[REDACTED]', $message);
            }
        }
        $message = (string) preg_replace('/\b(Authorization|Proxy-Authorization|Cookie|Set-Cookie)\s*:\s*[^\r\n,}]+/iu', '$1: [REDACTED]', $message);

        return strlen($message) > 500 ? substr($message, 0, 500).'…' : $message;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'apiKey' => '[REDACTED]',
            'projectId' => $this->projectId,
            'timeout' => $this->timeout,
            'retryTimes' => $this->retryTimes,
            'retryDelay' => $this->retryDelay,
            'client' => $this->client::class,
        ];
    }

    protected function parseErrorMessage(GuzzleException $e): string
    {
        if ($e instanceof RequestException && $e->hasResponse()) {
            /** @var ResponseInterface $response */
            $response = $e->getResponse();
            $body = json_decode($response->getBody()->getContents(), true);

            return $this->formatErrorMessage($body);
        }

        return $e->getMessage();
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    protected function formatErrorMessage(?array $body): string
    {
        if (isset($body['errors'])) {
            return 'Validation failed: '.json_encode($body['errors']);
        }

        return $body['message'] ?? 'Unknown error occurred';
    }
}
