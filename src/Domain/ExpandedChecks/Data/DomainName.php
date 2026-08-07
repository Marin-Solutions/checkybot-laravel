<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Data;

use InvalidArgumentException;

final readonly class DomainName
{
    public string $ascii;

    public function __construct(string $domain)
    {
        $domain = rtrim(trim($domain), '.');
        if ($domain === '' || str_contains($domain, '://') || preg_match('/[\/@:\s?#]/u', $domain) === 1) {
            throw new InvalidArgumentException('A domain must not contain a scheme, path, credentials, port, query, or fragment.');
        }

        $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($ascii === false) {
            throw new InvalidArgumentException('The domain could not be converted to canonical ASCII.');
        }
        $ascii = strtolower($ascii);
        if (strlen($ascii) > 253
            || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $ascii) !== 1) {
            throw new InvalidArgumentException('The domain is not a valid canonical host name.');
        }

        $this->ascii = $ascii;
    }

    public function __toString(): string
    {
        return $this->ascii;
    }
}
