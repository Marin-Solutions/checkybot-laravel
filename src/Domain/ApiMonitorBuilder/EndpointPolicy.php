<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder;

use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Exceptions\UnsafeEndpoint;

final readonly class EndpointPolicy
{
    public function __construct(private DnsResolver $dns) {}

    public function guard(string $endpoint): SafeEndpoint
    {
        $parts = parse_url($endpoint);
        if (! is_array($parts)) {
            throw new UnsafeEndpoint;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeEndpoint;
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        if ($this->isHarnessAllowlisted($endpoint)) {
            return new SafeEndpoint($endpoint, $host, $port, null);
        }

        $addresses = $this->dns->addresses($host);
        if ($addresses === []) {
            throw new UnsafeEndpoint;
        }
        foreach ($addresses as $address) {
            if (! $this->isPublicAddress($address)) {
                throw new UnsafeEndpoint;
            }
        }

        return new SafeEndpoint($endpoint, $host, $port, $addresses[0]);
    }

    private function isHarnessAllowlisted(string $endpoint): bool
    {
        if (! app()->environment(['testing', 'harness'])) {
            return false;
        }
        $allowlist = config('checkybot.api_builder.sample_exact_allowlist', []);

        return is_array($allowlist) && in_array($endpoint, array_filter($allowlist, 'is_string'), true);
    }

    private function isPublicAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
