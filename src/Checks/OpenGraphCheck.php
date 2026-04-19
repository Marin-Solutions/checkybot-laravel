<?php

namespace MarinSolutions\CheckybotLaravel\Checks;

/**
 * Fluent builder for OpenGraph tag validation checks.
 *
 * OpenGraph checks monitor a page to ensure required OpenGraph
 * meta tags are present and valid.
 *
 * @example
 * ```php
 * use MarinSolutions\CheckybotLaravel\Facades\Checkybot;
 *
 * // Simple OpenGraph check
 * Checkybot::openGraph('homepage-og')
 *     ->url('https://example.com')
 *     ->daily();
 *
 * // With required tags
 * Checkybot::openGraph('blog-og')
 *     ->url('https://example.com/blog')
 *     ->every('12h')
 *     ->requireTags(['og:title', 'og:description', 'og:image', 'og:url']);
 *
 * // Protected page with headers
 * Checkybot::openGraph('dashboard-og')
 *     ->url('https://example.com/dashboard')
 *     ->withToken(config('services.monitoring.token'))
 *     ->daily();
 * ```
 */
class OpenGraphCheck extends BaseCheck
{
    /**
     * Required OpenGraph tags that must be present.
     *
     * @var array<int, string>
     */
    protected array $requiredTags = [];

    /**
     * HTTP headers to send with the request.
     *
     * @var array<string, string>
     */
    protected array $headers = [];

    /**
     * Set the required OpenGraph tags.
     *
     * @param  array<int, string>  $tags  Tag names that must exist (e.g., 'og:title')
     * @return $this
     */
    public function requireTags(array $tags): self
    {
        $this->requiredTags = $tags;

        return $this;
    }

    /**
     * Require a single OpenGraph tag.
     *
     * @param  string  $tag  Tag name that must exist
     * @return $this
     */
    public function requireTag(string $tag): self
    {
        $this->requiredTags[] = $tag;

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

        if (! empty($this->requiredTags)) {
            $data['required_tags'] = $this->requiredTags;
        }

        if (! empty($this->headers)) {
            $data['headers'] = $this->headers;
        }

        return $data;
    }
}
