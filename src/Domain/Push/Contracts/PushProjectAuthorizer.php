<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Push\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface PushProjectAuthorizer
{
    public function canAccess(Authenticatable $user, string $projectUuid): bool;
}
