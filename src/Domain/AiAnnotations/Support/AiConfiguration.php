<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Support;

final class AiConfiguration
{
    public function providerConfigured(): bool
    {
        $url = config('ai-annotations.provider.url');
        $parts = is_string($url) ? parse_url($url) : false;

        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && $parts['host'] !== ''
            && ! isset($parts['user'], $parts['pass'], $parts['fragment'])
            && is_string(config('ai-annotations.provider.credential'))
            && trim((string) config('ai-annotations.provider.credential')) !== ''
            && is_string(config('ai-annotations.provider.model'))
            && trim((string) config('ai-annotations.provider.model')) !== ''
            && $this->positive('ai-annotations.limits.max_output_tokens')
            && $this->positive('ai-annotations.provider.connect_timeout_seconds')
            && $this->positive('ai-annotations.provider.timeout_seconds');
    }

    public function canEnable(): bool
    {
        $maximum = (int) config('ai-annotations.budget.max_request_microusd', 0);

        return $this->providerConfigured()
            && $maximum > 0
            && (int) config('ai-annotations.budget.project_monthly_limit_microusd', 0) >= $maximum
            && (int) config('ai-annotations.budget.global_monthly_limit_microusd', 0) >= $maximum;
    }

    private function positive(string $key): bool
    {
        return (float) config($key, 0) > 0;
    }
}
