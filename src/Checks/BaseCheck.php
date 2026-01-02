<?php

namespace MarinSolutions\CheckybotLaravel\Checks;

/**
 * Base class for all check types.
 *
 * Provides common functionality shared across uptime, SSL, and API checks.
 *
 * @internal
 */
abstract class BaseCheck
{
    /**
     * The unique name/identifier for this check.
     */
    protected string $name;

    /**
     * The URL to monitor.
     */
    protected string $url = '';

    /**
     * Check interval (e.g., '5m', '1h', '1d').
     */
    protected string $interval = '5m';

    /**
     * Create a new check instance.
     *
     * @param  string  $name  Unique identifier for this check
     */
    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * Set the URL to monitor.
     *
     * @param  string  $url  Full URL to monitor
     * @return $this
     *
     * @example
     * ```php
     * Checkybot::uptime('homepage')
     *     ->url('https://example.com');
     * ```
     */
    public function url(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    /**
     * Set how often the check should run.
     *
     * Accepts interval strings like '1m', '5m', '1h', '1d'.
     *
     * @param  string  $interval  Interval string (e.g., '5m' for 5 minutes)
     * @return $this
     *
     * @example
     * ```php
     * Checkybot::uptime('homepage')
     *     ->url('https://example.com')
     *     ->every('5m');
     * ```
     */
    public function every(string $interval): static
    {
        $this->interval = $interval;

        return $this;
    }

    /**
     * Alias for every() - set the check interval.
     *
     * @param  string  $interval  Interval string (e.g., '5m' for 5 minutes)
     * @return $this
     *
     * @see every()
     */
    public function interval(string $interval): static
    {
        return $this->every($interval);
    }

    /**
     * Run this check every minute.
     *
     * @return $this
     */
    public function everyMinute(): static
    {
        return $this->every('1m');
    }

    /**
     * Run this check every 5 minutes.
     *
     * @return $this
     */
    public function everyFiveMinutes(): static
    {
        return $this->every('5m');
    }

    /**
     * Run this check every 10 minutes.
     *
     * @return $this
     */
    public function everyTenMinutes(): static
    {
        return $this->every('10m');
    }

    /**
     * Run this check every 15 minutes.
     *
     * @return $this
     */
    public function everyFifteenMinutes(): static
    {
        return $this->every('15m');
    }

    /**
     * Run this check every 30 minutes.
     *
     * @return $this
     */
    public function everyThirtyMinutes(): static
    {
        return $this->every('30m');
    }

    /**
     * Run this check every hour.
     *
     * @return $this
     */
    public function hourly(): static
    {
        return $this->every('1h');
    }

    /**
     * Run this check every day.
     *
     * @return $this
     */
    public function daily(): static
    {
        return $this->every('1d');
    }

    /**
     * Get the check name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the check URL.
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Get the check interval.
     */
    public function getInterval(): string
    {
        return $this->interval;
    }

    /**
     * Convert the check to array format for the API.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;
}
