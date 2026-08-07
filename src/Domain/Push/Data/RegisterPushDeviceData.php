<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Push\Data;

final readonly class RegisterPushDeviceData
{
    public function __construct(
        public string $installationId,
        public string $expoPushToken,
        public string $platform,
        public string $projectUuid,
        public string $permission,
        public string $appVersion,
    ) {}
}
