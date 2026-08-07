<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Exceptions;

use RuntimeException;

final class UnsafeEndpoint extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The endpoint must resolve to an allowed public HTTP target.');
    }
}
