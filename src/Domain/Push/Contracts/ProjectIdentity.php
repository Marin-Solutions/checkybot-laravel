<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Push\Contracts;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

final readonly class ProjectIdentity
{
    public function __construct(public string $uuid)
    {
        if (! Uuid::isValid($uuid)) {
            throw new InvalidArgumentException('Project identity must be a UUID.');
        }
    }
}
