<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Push\Models\PushDevice;
use MarinSolutions\CheckybotLaravel\Domain\Push\Models\PushOperation;
use MarinSolutions\CheckybotLaravel\Models\OutboxEvent;
use Symfony\Component\Process\Process;

function runPushEndToEndWorker(string $database): Process
{
    $root = dirname(__DIR__, 3);
    $process = new Process([PHP_BINARY, dirname(__DIR__).'/MonitoringFoundation/Support/queue-worker.php', $root, $database], $root, [
        'APP_ENV' => 'testing', 'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'QUEUE_CONNECTION' => 'database',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
        'CHECKYBOT_EXPO_DELIVERY_FAKE' => 'accepted', 'CHECKYBOT_LEGACY_WEBHOOK_FAKE' => 'accepted',
        'CHECKYBOT_PUSH_PROVING_ENABLED' => 'true', 'CHECKYBOT_LEGACY_ALERT_WEBHOOK_URL' => 'https://legacy.example.test/runtime-secret',
    ]);
    $process->setTimeout(45);
    $process->run();

    return $process;
}

beforeEach(function (): void {
    $directory = dirname(__DIR__, 3).'/build/push-e2e-tests';
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    $this->pushE2eDatabase = $directory.'/'.Str::uuid().'.sqlite';
    touch($this->pushE2eDatabase);
    config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => $this->pushE2eDatabase, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 10000]);
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', ['driver' => 'database', 'connection' => 'sqlite', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => true]);
    DB::purge('sqlite');
    foreach (['2026_08_06_000000_create_monitor_foundation_tables.php', '2026_08_06_010000_create_alerting_result_runtime_tables.php', '2026_08_06_010100_create_alerting_incident_group_tables.php', '2026_08_06_010200_create_maintenance_mode_tables.php', '2026_08_06_020000_create_push_delivery_tables.php'] as $migration) {
        (include dirname(__DIR__, 3).'/database/migrations/'.$migration)->up();
    }
    Schema::create('jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
});

afterEach(function (): void {
    DB::disconnect('sqlite');
    @unlink($this->pushE2eDatabase);
});

it('crosses real alerting routes outbox relay grouping and queue workers to observable push receipts', function (): void {
    $project = (string) Str::uuid();
    $monitor = (string) Str::uuid();
    PushDevice::query()->create([
        'user_id' => 'e2e-user', 'installation_id' => (string) Str::uuid(), 'project_id' => $project, 'platform' => 'ios',
        'expo_push_token' => 'ExponentPushToken[e2e]', 'expo_token_hash' => hash('sha256', 'ExponentPushToken[e2e]'),
        'permission' => 'granted', 'app_version' => '1.0.0', 'active' => true, 'registered_at' => now(),
    ]);
    $base = CarbonImmutable::instance(now())->utc()->subSeconds(45);
    for ($index = 0; $index < 3; $index++) {
        $operationId = (string) Str::uuid();
        $this->postJson('/__harness/alerting/results', [
            'operation_id' => $operationId,
            'identity' => ['project_uuid' => $project, 'monitor_uuid' => $monitor, 'type' => 'server'],
            'source' => 'push', 'observed_at' => $base->addSeconds($index)->toRfc3339String(), 'signal' => 'critical',
            'reason_code' => 'threshold-exceeded', 'value' => 95.0,
            'thresholds' => ['warn' => 50, 'critical' => 80, 'recovery_delta' => 5],
        ])->assertAccepted()->assertJsonPath('operation_id', $operationId);
    }

    $alertWorker = runPushEndToEndWorker($this->pushE2eDatabase);
    expect($alertWorker->isSuccessful())->toBeTrue($alertWorker->getErrorOutput().$alertWorker->getOutput())
        ->and(OutboxEvent::query()->where('event_type', 'notification.intent.created')->count())->toBe(1);

    expect(Artisan::call('checkybot:foundation-relay'))->toBe(0);
    $deliveryWorker = runPushEndToEndWorker($this->pushE2eDatabase);
    expect($deliveryWorker->isSuccessful())->toBeTrue($deliveryWorker->getErrorOutput().$deliveryWorker->getOutput());

    $operation = PushOperation::query()->sole();
    $receipt = $this->getJson('/__harness/push/receipts/'.$operation->operation_id)->assertOk()
        ->assertJsonPath('listener_status', 'processed')
        ->assertJsonPath('legacy_webhook', 'accepted')
        ->assertJsonPath('reliability_recorded', true)
        ->assertJsonPath('expo_deliveries.0.status', 'accepted')
        ->assertJsonPath('expo_deliveries.0.refresh_widget', true)
        ->assertJsonPath('expo_deliveries.0.priority', 'high')
        ->assertJsonPath('expo_deliveries.0.sound', 'default');
    expect(json_encode($receipt->json()))->not->toContain('ExponentPushToken', 'runtime-secret');
})->group('AC-push-mobile-widget-status-5');
