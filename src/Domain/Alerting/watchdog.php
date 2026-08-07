<?php

declare(strict_types=1);

return [
    // This file is loaded as package configuration by AlertingServiceProvider.
    // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
    'url' => env('CHECKYBOT_WATCHDOG_URL'),
    // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
    'timeout_seconds' => env('CHECKYBOT_WATCHDOG_TIMEOUT', 5),
];
