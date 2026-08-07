<?php

namespace MarinSolutions\CheckybotLaravel\Checks;

use InvalidArgumentException;

/**
 * Fluent builder for API endpoint monitoring checks.
 *
 * API checks monitor endpoints and can validate JSON responses
 * using fluent assertions inspired by Pest's expectation API.
 *
 * @example
 * ```php
 * use MarinSolutions\CheckybotLaravel\Facades\Checkybot;
 *
 * // Simple API check
 * Checkybot::api('health')
 *     ->url('https://example.com/api/health')
 *     ->every('5m');
 *
 * // With headers
 * Checkybot::api('authenticated-endpoint')
 *     ->url('https://example.com/api/status')
 *     ->headers(['Authorization' => 'Bearer token'])
 *     ->every('5m');
 *
 * // With fluent assertions (Pest-style)
 * Checkybot::api('health')
 *     ->url('https://example.com/api/health')
 *     ->every('5m')
 *     ->expect('status')->toEqual('healthy')
 *     ->expect('database.connected')->toBeTrue()
 *     ->expect('queue.size')->toBeLessThan(1000);
 * ```
 */
class ApiCheck extends BaseCheck
{
    /**
     * HTTP headers to send with the request.
     *
     * @var array<string, string>
     */
    protected array $headers = [];

    protected ?int $expectedStatus = null;

    protected ?int $maxLatencyMs = null;

    protected ?int $retryCount = null;

    /**
     * Response assertions.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $assertions = [];

    /**
     * Set HTTP headers to send with the request.
     *
     * @param  array<string, string>  $headers  Key-value pairs of headers
     * @return $this
     *
     * @example
     * ```php
     * Checkybot::api('authenticated')
     *     ->url('https://example.com/api/status')
     *     ->headers([
     *         'Authorization' => 'Bearer ' . config('services.monitoring.token'),
     *         'Accept' => 'application/json',
     *     ])
     *     ->every('5m');
     * ```
     */
    public function headers(array $headers): self
    {
        $this->headers = $headers;

        return $this;
    }

    /**
     * Add a single header to the request.
     *
     * @param  string  $name  Header name
     * @param  string  $value  Header value
     * @return $this
     *
     * @example
     * ```php
     * Checkybot::api('endpoint')
     *     ->url('https://example.com/api')
     *     ->withHeader('Authorization', 'Bearer token')
     *     ->withHeader('Accept', 'application/json')
     *     ->every('5m');
     * ```
     */
    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * Add bearer token authorization header.
     *
     * @param  string  $token  Bearer token
     * @return $this
     *
     * @example
     * ```php
     * Checkybot::api('endpoint')
     *     ->url('https://example.com/api')
     *     ->withToken(config('services.monitoring.token'))
     *     ->every('5m');
     * ```
     */
    public function withToken(string $token): self
    {
        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    public function expectStatus(int $status): self
    {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException('Expected status must be between 100 and 599.');
        }

        $this->expectedStatus = $status;
        $this->replaceMetadataAssertion('status', [
            'kind' => 'status',
            'operator' => 'equals',
            'operand' => $status,
        ]);

        return $this;
    }

    public function maxLatency(int $milliseconds): self
    {
        if ($milliseconds < 1 || $milliseconds > 3_600_000) {
            throw new InvalidArgumentException('Maximum latency must be between 1 and 3600000 milliseconds.');
        }

        $this->maxLatencyMs = $milliseconds;
        $this->replaceMetadataAssertion('latency', [
            'kind' => 'latency',
            'operator' => 'less_than_or_equal',
            'operand' => $milliseconds,
        ]);

        return $this;
    }

    public function retries(int $count): self
    {
        if ($count < 0 || $count > 10) {
            throw new InvalidArgumentException('Retry count must be between 0 and 10.');
        }

        $this->retryCount = $count;

        return $this;
    }

    /**
     * Start building an assertion for a JSON path.
     *
     * Returns a PendingAssertion that provides fluent assertion methods
     * inspired by Pest's expectation API.
     *
     * @param  string  $path  JSON path to assert on (dot notation supported)
     *
     * @example
     * ```php
     * Checkybot::api('health')
     *     ->url('https://example.com/api/health')
     *     ->expect('status')->toEqual('healthy')
     *     ->expect('database.connected')->toBeTrue()
     *     ->expect('queue.size')->toBeLessThan(1000);
     * ```
     */
    public function expect(string $path): PendingAssertion
    {
        return new PendingAssertion($this, $path);
    }

    /**
     * Assert that a path exists in the response.
     *
     * Shorthand for ->expect($path)->toExist()
     *
     * @param  string  $path  JSON path to check
     * @return $this
     *
     * @example
     * ```php
     * Checkybot::api('health')
     *     ->url('https://example.com/api/health')
     *     ->expectPathExists('status')
     *     ->expectPathExists('database')
     *     ->every('5m');
     * ```
     */
    public function expectPathExists(string $path): self
    {
        return $this->addAssertion([
            'kind' => 'json_path',
            'operator' => 'exists',
            'path' => $path,
        ]);
    }

    /**
     * Add a raw assertion array.
     *
     * Used internally by PendingAssertion.
     *
     * @param  array<string, mixed>  $assertion  Assertion configuration
     * @return $this
     *
     * @internal
     */
    public function addAssertion(array $assertion): self
    {
        $assertion['sort_order'] = count($this->assertions) + 1;
        $assertion['is_active'] = true;
        $this->assertions[] = $assertion;

        return $this;
    }

    /** @param array<string, mixed> $assertion */
    private function replaceMetadataAssertion(string $kind, array $assertion): void
    {
        foreach ($this->assertions as $index => $existing) {
            if (($existing['kind'] ?? null) === $kind) {
                $assertion['sort_order'] = $existing['sort_order'];
                $assertion['is_active'] = true;
                $this->assertions[$index] = $assertion;

                return;
            }
        }

        $this->addAssertion($assertion);
    }

    /**
     * Convert the check to array format for the API.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'url' => $this->url,
            'interval' => $this->interval,
        ];

        if (! empty($this->headers)) {
            $data['headers'] = $this->headers;
        }

        if ($this->expectedStatus !== null) {
            $data['expected_status'] = $this->expectedStatus;
        }

        if ($this->maxLatencyMs !== null) {
            $data['max_latency_ms'] = $this->maxLatencyMs;
        }

        if ($this->retryCount !== null) {
            $data['retry_count'] = $this->retryCount;
        }

        if (! empty($this->assertions)) {
            $data['assertions'] = $this->assertions;
        }

        return $data;
    }
}
