<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Tests\Feature\ExpandedChecks\Support;

use Carbon\CarbonImmutable;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Contracts\DomainExpiryLookup;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Data\DomainLookupResult;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Data\DomainName;
use RuntimeException;

final readonly class FileSequenceDomainExpiryLookup implements DomainExpiryLookup
{
    public function __construct(private string $statePath) {}

    public function lookup(DomainName $domain): DomainLookupResult
    {
        $handle = fopen($this->statePath, 'c+');
        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock the domain lookup test state.');
        }

        try {
            rewind($handle);
            $contents = stream_get_contents($handle);
            /** @var array{responses?: list<string>, calls?: list<array{domain: string, at: string}>, expires_at?: string} $state */
            $state = $contents === false || $contents === '' ? [] : json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            $responses = $state['responses'] ?? ['success'];
            $calls = $state['calls'] ?? [];
            $response = $responses[min(count($calls), count($responses) - 1)];
            $now = CarbonImmutable::now('UTC');
            $calls[] = ['domain' => $domain->ascii, 'at' => $now->toRfc3339String()];
            $state['calls'] = $calls;

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return match ($response) {
            'timeout' => DomainLookupResult::failure($domain, 'domain_lookup_timeout', true, $now),
            'no_expiry' => DomainLookupResult::failure($domain, 'domain_lookup_no_expiry', true, $now),
            'malformed' => DomainLookupResult::failure($domain, 'domain_lookup_malformed', true, $now),
            'success' => DomainLookupResult::success(
                $domain,
                CarbonImmutable::parse($state['expires_at'] ?? '2028-01-01T00:00:00Z')->utc(),
                'rdap',
                $now,
            ),
            default => throw new RuntimeException("Unknown domain lookup test response [{$response}]."),
        };
    }
}
