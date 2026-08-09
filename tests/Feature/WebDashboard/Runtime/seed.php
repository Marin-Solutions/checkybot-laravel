<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Models\ApiMonitorConfiguration;
use MarinSolutions\CheckybotLaravel\Domain\Security\Foundation\EncryptedHeaderValue;
use MarinSolutions\CheckybotLaravel\Models\MonitorState;

if ($argc < 3 || ! in_array($argv[1], ['initial', 'maintenance'], true)) {
    fwrite(STDERR, "Usage: seed.php initial|maintenance <project-uuid> [api-monitor-uuid sample-url]\n");
    exit(64);
}

$app = require dirname(__DIR__, 4).'/scripts/harness/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$mode = $argv[1];
$project = strtolower($argv[2]);

if ($mode === 'initial') {
    if ($argc !== 5) {
        fwrite(STDERR, "Initial seed requires api-monitor-uuid and sample-url.\n");
        exit(64);
    }
    $monitor = strtolower($argv[3]);
    MonitorState::query()->create([
        'project_id' => $project,
        'monitor_id' => $monitor,
        'monitor_type' => 'api',
        'state' => 'healthy',
        'severity' => 'warn',
        'observed_at' => now(),
    ]);
    $configuration = ApiMonitorConfiguration::query()->create([
        'project_id' => $project,
        'monitor_id' => $monitor,
        'method' => 'GET',
        'endpoint' => $argv[4],
        'version' => 1,
    ]);
    DB::table('api_monitor_builder_headers')->insert([
        'configuration_id' => $configuration->id,
        'name' => 'Authorization',
        'normalized_name' => 'authorization',
        'encrypted_value' => EncryptedHeaderValue::encrypt('Bearer runtime-secret')->ciphertext(),
        'position' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('api_monitor_builder_assertions')->insert([
        'configuration_id' => $configuration->id,
        'kind' => 'status',
        'operator' => 'equals',
        'json_path' => null,
        'expected_value' => '200',
        'has_expected' => true,
        'position' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    fwrite(STDOUT, "Seeded runtime API monitor {$monitor}.\n");
    exit(0);
}

DB::table('maintenance_modes')->insert([
    'public_id' => (string) Str::uuid(),
    'operation_id' => (string) Str::uuid(),
    'payload_hash' => hash('sha256', 'runtime-project-maintenance'),
    'scope' => 'project',
    'project_id' => $project,
    'reason' => 'Runtime maintenance window',
    'starts_at' => now()->subMinute(),
    'ends_at' => now()->addHour(),
    'cleared_at' => null,
    'catch_up_queued_at' => null,
    'catch_up_claimed_at' => null,
    'created_at' => now(),
    'updated_at' => now(),
]);
fwrite(STDOUT, "Seeded active project maintenance.\n");
