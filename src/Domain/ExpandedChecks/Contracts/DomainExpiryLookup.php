<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Contracts;

use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Data\DomainLookupResult;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Data\DomainName;

interface DomainExpiryLookup
{
    public function lookup(DomainName $domain): DomainLookupResult;
}
