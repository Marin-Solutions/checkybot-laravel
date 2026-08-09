<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Maintenance\Data;

use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Models\MaintenanceMode;

final readonly class CreateMaintenanceResult
{
    public function __construct(public MaintenanceMode $mode, public bool $created) {}
}
