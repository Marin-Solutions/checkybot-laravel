<?php

namespace MarinSolutions\CheckybotLaravel\Checks;

/**
 * Fluent definition for a domain-expiry monitor.
 */
class DomainExpiryCheck extends BaseCheck
{
    protected int $warnDays = 30;

    /**
     * Set how many days before expiry should trigger a warning.
     *
     * @return $this
     */
    public function warnDays(int $days): self
    {
        $this->warnDays = $days;

        return $this;
    }

    /** @return array{name: string, url: string, interval: string, warn_days: int} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'url' => $this->url,
            'interval' => $this->interval,
            'warn_days' => $this->warnDays,
        ];
    }
}
