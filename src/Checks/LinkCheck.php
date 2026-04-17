<?php

namespace MarinSolutions\CheckybotLaravel\Checks;

/**
 * Fluent builder for dead link monitoring checks.
 *
 * Link checks monitor a page and verify that all links on it
 * are valid and not returning 404 or other error status codes.
 *
 * @example
 * ```php
 * use MarinSolutions\CheckybotLaravel\Facades\Checkybot;
 *
 * // Simple link check
 * Checkybot::links('homepage-links')
 *     ->url('https://example.com')
 *     ->daily();
 *
 * // With custom depth and exclusions
 * Checkybot::links('docs-links')
 *     ->url('https://example.com/docs')
 *     ->every('12h')
 *     ->maxDepth(2)
 *     ->exclude(['/admin/*', '/logout']);
 *
 * // Protected page with headers
 * Checkybot::links('dashboard-links')
 *     ->url('https://example.com/dashboard')
 *     ->withToken(config('services.monitoring.token'))
 *     ->daily();
 * ```
 */
class LinkCheck extends BaseCheck
{
    /**
     * Maximum crawl depth from the starting URL.
     */
    protected ?int $maxDepth = null;

    /**
     * Paths or patterns to exclude from link checking.
     *
     * @var array<int, string>
     */
    protected array $excludePaths = [];

    /**
     * HTTP headers to send with the request.
     *
     * @var array<string, string>
     */
    protected array $headers = [];

    /**
     * Set the maximum crawl depth.
     *
     * @param  int  $depth  Maximum depth to crawl (default: 1)
     * @return $this
     */
    public function maxDepth(int $depth): self
    {
        $this->maxDepth = $depth;

        return $this;
    }

    /**
     * Set paths or patterns to exclude from link checking.
     *
     * @param  array<int, string>  $paths  Patterns to exclude
     * @return $this
     */
    public function exclude(array $paths): self
    {
        $this->excludePaths = $paths;

        return $this;
    }

    /**
     * Set HTTP headers to send with the request.
     *
     * @param  array<string, string>  $headers  Key-value pairs of headers
     * @return $this
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
     */
    public function withToken(string $token): self
    {
        return $this->withHeader('Authorization', 'Bearer '.$token);
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

        if ($this->maxDepth !== null) {
            $data['max_depth'] = $this->maxDepth;
        }

        if (! empty($this->excludePaths)) {
            $data['exclude_paths'] = $this->excludePaths;
        }

        if (! empty($this->headers)) {
            $data['headers'] = $this->headers;
        }

        return $data;
    }
}
