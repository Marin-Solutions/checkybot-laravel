<?php

namespace MarinSolutions\CheckybotLaravel;

use MarinSolutions\CheckybotLaravel\Checks\ApiCheck;
use MarinSolutions\CheckybotLaravel\Checks\SslCheck;
use MarinSolutions\CheckybotLaravel\Checks\UptimeCheck;

/**
 * Registry for all monitoring checks.
 *
 * This class serves as the central store for all defined checks,
 * accessible via the Checkybot facade.
 *
 * @see \MarinSolutions\CheckybotLaravel\Facades\Checkybot
 */
class CheckRegistry
{
    /**
     * Registered uptime checks.
     *
     * @var array<int, UptimeCheck>
     */
    protected array $uptimeChecks = [];

    /**
     * Registered SSL checks.
     *
     * @var array<int, SslCheck>
     */
    protected array $sslChecks = [];

    /**
     * Registered API checks.
     *
     * @var array<int, ApiCheck>
     */
    protected array $apiChecks = [];

    /**
     * Create a new uptime check.
     *
     * Uptime checks monitor website availability and response times.
     *
     * @param  string  $name  Unique identifier for this check
     * @return UptimeCheck Fluent builder for configuring the check
     *
     * @example
     * ```php
     * Checkybot::uptime('homepage')
     *     ->url('https://example.com')
     *     ->every('5m');
     * ```
     */
    public function uptime(string $name): UptimeCheck
    {
        $check = new UptimeCheck($name);
        $this->uptimeChecks[] = $check;

        return $check;
    }

    /**
     * Create a new SSL certificate check.
     *
     * SSL checks monitor certificate expiration dates.
     *
     * @param  string  $name  Unique identifier for this check
     * @return SslCheck Fluent builder for configuring the check
     *
     * @example
     * ```php
     * Checkybot::ssl('main-certificate')
     *     ->url('https://example.com')
     *     ->every('1d');
     * ```
     */
    public function ssl(string $name): SslCheck
    {
        $check = new SslCheck($name);
        $this->sslChecks[] = $check;

        return $check;
    }

    /**
     * Create a new API endpoint check.
     *
     * API checks monitor endpoints and can validate JSON responses
     * using fluent assertions.
     *
     * @param  string  $name  Unique identifier for this check
     * @return ApiCheck Fluent builder for configuring the check
     *
     * @example
     * ```php
     * Checkybot::api('health-endpoint')
     *     ->url('https://example.com/api/health')
     *     ->every('5m')
     *     ->expect('status')->toEqual('healthy');
     * ```
     */
    public function api(string $name): ApiCheck
    {
        $check = new ApiCheck($name);
        $this->apiChecks[] = $check;

        return $check;
    }

    /**
     * Get all registered uptime checks.
     *
     * @return array<int, UptimeCheck>
     */
    public function getUptimeChecks(): array
    {
        return $this->uptimeChecks;
    }

    /**
     * Get all registered SSL checks.
     *
     * @return array<int, SslCheck>
     */
    public function getSslChecks(): array
    {
        return $this->sslChecks;
    }

    /**
     * Get all registered API checks.
     *
     * @return array<int, ApiCheck>
     */
    public function getApiChecks(): array
    {
        return $this->apiChecks;
    }

    /**
     * Get the total number of registered checks.
     */
    public function count(): int
    {
        return count($this->uptimeChecks)
            + count($this->sslChecks)
            + count($this->apiChecks);
    }

    /**
     * Clear all registered checks.
     *
     * Useful for testing or re-registration scenarios.
     *
     * @return $this
     */
    public function flush(): self
    {
        $this->uptimeChecks = [];
        $this->sslChecks = [];
        $this->apiChecks = [];

        return $this;
    }

    /**
     * Convert all checks to array format for API payload.
     *
     * @return array{uptime_checks: array<int, array<string, mixed>>, ssl_checks: array<int, array<string, mixed>>, api_checks: array<int, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'uptime_checks' => array_map(fn (UptimeCheck $check) => $check->toArray(), $this->uptimeChecks),
            'ssl_checks' => array_map(fn (SslCheck $check) => $check->toArray(), $this->sslChecks),
            'api_checks' => array_map(fn (ApiCheck $check) => $check->toArray(), $this->apiChecks),
        ];
    }
}
