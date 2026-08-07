<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class DomainLookupResult
{
    private function __construct(
        public bool $successful,
        public DomainName $domain,
        public ?CarbonImmutable $expiresAt,
        public ?string $source,
        public CarbonImmutable $fetchedAt,
        public ?string $failureCode,
        public bool $retryable,
    ) {}

    public static function success(DomainName $domain, CarbonImmutable $expiresAt, string $source, CarbonImmutable $fetchedAt): self
    {
        if (! in_array($source, ['whois', 'rdap'], true)) {
            throw new InvalidArgumentException('Domain expiry source must be whois or rdap.');
        }

        return new self(true, $domain, $expiresAt->utc(), $source, $fetchedAt->utc(), null, false);
    }

    public static function failure(DomainName $domain, string $code, bool $retryable, CarbonImmutable $fetchedAt): self
    {
        if (preg_match('/^[a-z0-9_]{1,120}$/', $code) !== 1) {
            throw new InvalidArgumentException('Lookup failure codes must be bounded and redacted.');
        }

        return new self(false, $domain, null, null, $fetchedAt->utc(), $code, $retryable);
    }
}
