<?php

namespace MarinSolutions\CheckybotLaravel\Checks;

/**
 * Fluent definition for a response-time percentile budget.
 */
class ResponseTimeBudgetCheck extends BaseCheck
{
    protected int $percentile = 95;

    protected int $budgetMs = 2000;

    /** @return $this */
    public function percentile(int $percentile): self
    {
        $this->percentile = $percentile;

        return $this;
    }

    /** @return $this */
    public function budgetMs(int $milliseconds): self
    {
        $this->budgetMs = $milliseconds;

        return $this;
    }

    /**
     * Configure the p95 budget in milliseconds.
     *
     * @return $this
     */
    public function p95(int $milliseconds): self
    {
        return $this->percentile(95)->budgetMs($milliseconds);
    }

    /** @return array{name: string, url: string, interval: string, percentile: int, budget_ms: int} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'url' => $this->url,
            'interval' => $this->interval,
            'percentile' => $this->percentile,
            'budget_ms' => $this->budgetMs,
        ];
    }
}
