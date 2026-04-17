<?php

namespace MarinSolutions\CheckybotLaravel;

use MarinSolutions\CheckybotLaravel\Checks\ApiCheck;
use MarinSolutions\CheckybotLaravel\Checks\LinkCheck;
use MarinSolutions\CheckybotLaravel\Checks\OpenGraphCheck;
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
     * Registered link checks.
     *
     * @var array<int, LinkCheck>
     */
    protected array $linkChecks = [];

    /**
     * Registered OpenGraph checks.
     *
     * @var array<int, OpenGraphCheck>
     */
    protected array $openGraphChecks = [];

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
     * Create a new dead link check.
     *
     * Link checks monitor a page and verify all links are valid.
     *
     * @param  string  $name  Unique identifier for this check
     * @return LinkCheck Fluent builder for configuring the check
     *
     * @example
     * ```php
     * Checkybot::links('homepage-links')
     *     ->url('https://example.com')
     *     ->daily();
     * ```
     */
    public function links(string $name): LinkCheck
    {
        $check = new LinkCheck($name);
        $this->linkChecks[] = $check;

        return $check;
    }

    /**
     * Create a new OpenGraph check.
     *
     * OpenGraph checks validate required OG meta tags on a page.
     *
     * @param  string  $name  Unique identifier for this check
     * @return OpenGraphCheck Fluent builder for configuring the check
     *
     * @example
     * ```php
     * Checkybot::openGraph('homepage-og')
     *     ->url('https://example.com')
     *     ->requireTags(['og:title', 'og:image'])
     *     ->daily();
     * ```
     */
    public function openGraph(string $name): OpenGraphCheck
    {
        $check = new OpenGraphCheck($name);
        $this->openGraphChecks[] = $check;

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
     * Get all registered link checks.
     *
     * @return array<int, LinkCheck>
     */
    public function getLinkChecks(): array
    {
        return $this->linkChecks;
    }

    /**
     * Get all registered OpenGraph checks.
     *
     * @return array<int, OpenGraphCheck>
     */
    public function getOpenGraphChecks(): array
    {
        return $this->openGraphChecks;
    }

    /**
     * Get the total number of registered checks.
     */
    public function count(): int
    {
        return count($this->uptimeChecks)
            + count($this->sslChecks)
            + count($this->apiChecks)
            + count($this->linkChecks)
            + count($this->openGraphChecks);
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
        $this->linkChecks = [];
        $this->openGraphChecks = [];

        return $this;
    }

    /**
     * Convert all checks to array format for API payload.
     *
     * @return array{uptime_checks: array<int, array<string, mixed>>, ssl_checks: array<int, array<string, mixed>>, api_checks: array<int, array<string, mixed>>, link_checks: array<int, array<string, mixed>>, open_graph_checks: array<int, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'uptime_checks' => array_map(fn (UptimeCheck $check) => $check->toArray(), $this->uptimeChecks),
            'ssl_checks' => array_map(fn (SslCheck $check) => $check->toArray(), $this->sslChecks),
            'api_checks' => array_map(fn (ApiCheck $check) => $check->toArray(), $this->apiChecks),
            'link_checks' => array_map(fn (LinkCheck $check) => $check->toArray(), $this->linkChecks),
            'open_graph_checks' => array_map(fn (OpenGraphCheck $check) => $check->toArray(), $this->openGraphChecks),
        ];
    }
}
