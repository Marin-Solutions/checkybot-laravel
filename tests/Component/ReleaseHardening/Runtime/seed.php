#!/usr/bin/env php
<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Push\Models\PushDevice;

if ($argc !== 2) {
    fwrite(STDERR, "Usage: seed.php <project-uuid>\n");
    exit(64);
}

$app = require dirname(__DIR__, 4).'/scripts/harness/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$project = strtolower($argv[1]);

PushDevice::query()->create([
    'user_id' => 'release-runtime-user',
    'installation_id' => (string) Str::uuid(),
    'project_id' => $project,
    'platform' => 'ios',
    'expo_push_token' => 'ExponentPushToken[release-runtime]',
    'expo_token_hash' => hash('sha256', 'ExponentPushToken[release-runtime]'),
    'permission' => 'granted',
    'app_version' => '1.0.0',
    'active' => true,
    'registered_at' => now(),
]);

fwrite(STDOUT, "Seeded one run-scoped push device for {$project}.\n");
