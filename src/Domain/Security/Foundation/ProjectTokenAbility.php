<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Security\Foundation;

enum ProjectTokenAbility: string
{
    case StatusRead = 'status:read';
}
