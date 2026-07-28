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
     * HTTP method to use for the request.
     */
    protected ?string $method = null;

    /**
     * Relative path to monitor when the Checkybot app should resolve the host.
     */
    protected ?string $path = null;

    /**
     * HTTP headers to send with the request.
     *
     * @var array<string, string>
     */
    protected array $headers = [];

    /**
     * Expected HTTP response status.
     */
    protected ?int $expectedStatus = null;

    protected ?int $retryCount = null;

    /**
     * Request timeout in seconds.
     */
    protected ?int $timeout = null;

    /**
     * JSON paths that must exist in the response.
     *
     * @var array<int, string>
     */
    protected array $requiredJsonPaths = [];

    /**
     * Body assertions for the response.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $bodyAssertions = [];

    /**
     * Response assertions.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $assertions = [];

    /**
     * Set the HTTP method.
     *
     * @return $this
     */
    public function method(string $method): self
    {
        $this->method = strtoupper($method);

        return $this;
    }

    /**
     * Set a relative path instead of a full URL.
     *
     * @return $this
     */
    public function path(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    /**
     * Get the configured relative path.
     */
    public function getPath(): ?string
    {
        return $this->path;
    }

    /**
     * Set the expected HTTP status code.
     *
     * @return $this
     */
    public function expectedStatus(int $status): self
    {
        $this->expectedStatus = $status;

        return $this;
    }

    /**
     * Set the request timeout in seconds.
     *
     * @return $this
     */
    public function timeout(int $seconds): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Require JSON paths to exist in the response.
     *
     * @param  array<int, string>  $paths
     * @return $this
     */
    public function requireJsonPaths(array $paths): self
    {
        $this->requiredJsonPaths = $paths;

        return $this;
    }

    /**
     * Set body assertions for the response.
     *
     * @param  array<int, array<string, mixed>>  $assertions
     * @return $this
     */
    public function bodyAssertions(array $assertions): self
    {
        $this->bodyAssertions = $assertions;

        return $this;
    }

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
            'data_path' => $path,
            'assertion_type' => 'exists',
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

    /**
     * Convert the check to array format for the API.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
        ];

        if (! empty($this->url)) {
            $data['url'] = $this->url;
        }

        if ($this->path !== null) {
            $data['path'] = $this->path;
        }

        if ($this->method !== null) {
            $data['method'] = $this->method;
        }

        $data['interval'] = $this->interval;

        if (! empty($this->headers)) {
            $data['headers'] = $this->headers;
        }

        if ($this->expectedStatus !== null) {
            $data['expected_status'] = $this->expectedStatus;
        }

        if ($this->timeout !== null) {
            $data['timeout'] = $this->timeout;
        }

        if (! empty($this->requiredJsonPaths)) {
            $data['required_json_paths'] = $this->requiredJsonPaths;
        }

        if (! empty($this->bodyAssertions)) {
            $data['body_assertions'] = $this->bodyAssertions;
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
