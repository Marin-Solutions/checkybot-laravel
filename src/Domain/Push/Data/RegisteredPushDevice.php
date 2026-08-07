<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Push\Data;

use MarinSolutions\CheckybotLaravel\Domain\Push\Models\PushDevice;

final readonly class RegisteredPushDevice
{
    public function __construct(public PushDevice $device, public bool $created) {}
}
