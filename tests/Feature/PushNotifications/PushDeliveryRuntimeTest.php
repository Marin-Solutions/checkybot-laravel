<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Push\Contracts\ProjectIdentity;
use MarinSolutions\CheckybotLaravel\Domain\Push\Delivery\PushPayloadFactory;
use MarinSolutions\CheckybotLaravel\Domain\Push\Jobs\DeliverExpoPush;
use MarinSolutions\CheckybotLaravel\Domain\Push\Jobs\ProcessNotificationIntent;
use MarinSolutions\CheckybotLaravel\Domain\Push\Models\PushDeliveryAttempt;
use MarinSolutions\CheckybotLaravel\Domain\Push\Models\PushDevice;
use MarinSolutions\CheckybotLaravel\Domain\Push\Models\PushOperation;
use MarinSolutions\CheckybotLaravel\Domain\Push\Queries\PushReliabilityReadModel;
use Symfony\Component\Process\Process;

function pushRuntimeOperation(string $project, string $severity = 'critical', ?string $operationId = null): PushOperation
{
    $monitor = (string) Str::uuid();

    return PushOperation::query()->create([
        'operation_id' => $operationId ?? (string) Str::uuid(), 'project_id' => $project,
        'intent_id' => (string) Str::uuid(), 'group_id' => (string) Str::uuid(),
        'phase' => 'incident', 'severity' => $severity, 'thread_key' => 'incident:'.$project,
        'payload' => ['problem_filter' => ['route' => 'problems', 'monitor_uuids' => [$monitor, $monitor]], 'opened_at' => now()->toRfc3339String(), 'emitted_at' => now()->toRfc3339String(), 'downtime_seconds' => null],
    ]);
}

function pushRuntimeDevice(string $project, string $token, bool $active = true): PushDevice
{
    return PushDevice::query()->create([
        'user_id' => (string) Str::uuid(), 'installation_id' => (string) Str::uuid(), 'project_id' => $project,
        'platform' => 'ios', 'expo_push_token' => $token, 'expo_token_hash' => hash('sha256', $token),
        'permission' => 'granted', 'app_version' => '1.0.0', 'active' => $active, 'registered_at' => now(),
        'deactivated_at' => $active ? null : now(),
    ]);
}

/** @param array<string, string> $environment */
function makePushRuntimeWorker(
    string $database,
    string $mode = 'empty',
    string $expoFake = 'accepted',
    string $legacyFake = 'accepted',
    ?string $barrier = null,
    ?string $ready = null,
    array $environment = [],
): Process {
    $root = dirname(__DIR__, 3);
    $process = new Process([
        PHP_BINARY,
        __DIR__.'/Support/push-queue-worker.php',
        $root,
        $database,
        $mode,
        $barrier ?? '-',
        $ready ?? '-',
    ], $root, [
        'APP_ENV' => 'testing',
        'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $database,
        'QUEUE_CONNECTION' => 'database',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'CHECKYBOT_EXPO_DELIVERY_FAKE' => $expoFake,
        'CHECKYBOT_LEGACY_WEBHOOK_FAKE' => $legacyFake,
        'CHECKYBOT_PUSH_PROVING_ENABLED' => 'true',
        'CHECKYBOT_LEGACY_ALERT_WEBHOOK_URL' => $legacyFake === '' ? '' : 'https://legacy.example.test/runtime-secret',
        ...$environment,
    ]);
    $process->setTimeout(120);

    return $process;
}

function runPushRuntimeWorker(
    string $database,
    string $mode = 'empty',
    string $expoFake = 'accepted',
    string $legacyFake = 'accepted',
): Process {
    $process = makePushRuntimeWorker($database, $mode, $expoFake, $legacyFake);
    $process->run();

    return $process;
}

beforeEach(function (): void {
    $directory = dirname(__DIR__, 3).'/build/push-runtime-tests';
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    $this->pushRuntimeDatabase = $directory.'/'.Str::uuid().'.sqlite';
    touch($this->pushRuntimeDatabase);
    config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => $this->pushRuntimeDatabase, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 10000]);
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', ['driver' => 'database', 'connection' => 'sqlite', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => true]);
    config()->set('checkybot.push.proving_enabled', true);
    config()->set('checkybot.push.legacy_webhook.url', 'https://legacy.example.test/hooks/secret-path');
    DB::purge('sqlite');
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_000000_create_monitor_foundation_tables.php')->up();
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_020000_create_push_delivery_tables.php')->up();
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
    CarbonImmutable::setTestNow();
    DB::disconnect('sqlite');
    @unlink($this->pushRuntimeDatabase);
});

it('claims duplicate intents through barrier synchronized real queue workers and fans out once', function (): void {
    $project = (string) Str::uuid();
    $operation = pushRuntimeOperation($project);
    pushRuntimeDevice($project, 'ExponentPushToken[first]');
    pushRuntimeDevice($project, 'ExponentPushToken[second]');
    pushRuntimeDevice($project, 'ExponentPushToken[inactive]', false);
    pushRuntimeDevice((string) Str::uuid(), 'ExponentPushToken[foreign]');
    ProcessNotificationIntent::dispatch($operation->operation_id);
    ProcessNotificationIntent::dispatch($operation->operation_id);

    $root = dirname(__DIR__, 3);
    $barrier = $root.'/build/push-runtime-tests/barrier-'.Str::uuid();
    $processes = [];
    $ready = [];
    for ($index = 0; $index < 2; $index++) {
        $ready[$index] = $barrier.'-ready-'.$index;
        $processes[$index] = makePushRuntimeWorker($this->pushRuntimeDatabase, 'once', 'accepted', 'accepted', $barrier, $ready[$index]);
        $processes[$index]->start();
    }
    $deadline = microtime(true) + 30;
    while (count(array_filter($ready, 'is_file')) !== 2 && microtime(true) < $deadline) {
        usleep(20000);
    }
    expect(count(array_filter($ready, 'is_file')))->toBe(2);
    touch($barrier);
    foreach ($processes as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
    }
    foreach ([$barrier, ...$ready] as $file) {
        @unlink($file);
    }

    expect(PushDeliveryAttempt::query()->where('operation_id', $operation->operation_id)->count())->toBe(3)
        ->and(PushDeliveryAttempt::query()->where('operation_id', $operation->operation_id)->where('channel', 'expo')->count())->toBe(2)
        ->and(PushDeliveryAttempt::query()->where('operation_id', $operation->operation_id)->where('channel', 'legacy_webhook')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBeGreaterThanOrEqual(3)
        ->and(DB::table('jobs')->count())->toBeLessThanOrEqual(4)
        ->and($operation->fresh()->status)->toBe('processed');

    $deliveryWorker = runPushRuntimeWorker($this->pushRuntimeDatabase);
    expect($deliveryWorker->isSuccessful())->toBeTrue($deliveryWorker->getErrorOutput().$deliveryWorker->getOutput())
        ->and(PushDeliveryAttempt::query()->where('operation_id', $operation->operation_id)->where('status', 'accepted')->count())->toBe(3)
        ->and(DB::table('jobs')->count())->toBe(0);

    $accepted = PushDeliveryAttempt::query()->where('operation_id', $operation->operation_id)->where('channel', 'expo')->firstOrFail();
    $ticket = $accepted->ticket_id;
    DeliverExpoPush::dispatch($accepted->public_id);
    $duplicateWorker = runPushRuntimeWorker($this->pushRuntimeDatabase);
    expect($duplicateWorker->isSuccessful())->toBeTrue($duplicateWorker->getErrorOutput().$duplicateWorker->getOutput())
        ->and($accepted->fresh()->provider_attempts)->toBe(1)
        ->and($accepted->fresh()->ticket_id)->toBe($ticket);
})->group('AC-push-mobile-widget-status-2');

it('builds typed bounded secret-free critical and warn payloads', function (): void {
    $project = (string) Str::uuid();
    $critical = pushRuntimeOperation($project, 'critical');
    $warn = pushRuntimeOperation($project, 'warn');
    $factory = app(PushPayloadFactory::class);
    $criticalPayload = $factory->make($critical);
    $warnPayload = $factory->make($warn);

    expect($criticalPayload)->toMatchArray(['priority' => 'high', 'sound' => 'default', 'interruptionLevel' => 'time-sensitive', '_contentAvailable' => true])
        ->and($criticalPayload['badge'])->toBeInt()
        ->and($criticalPayload['data'])->toMatchArray(['route' => 'problems', 'projectUuid' => $project, 'refreshWidget' => true, 'phase' => 'incident', 'severity' => 'critical'])
        ->and($criticalPayload['data']['monitorUuids'])->toHaveCount(1)
        ->and($warnPayload)->toMatchArray(['priority' => 'default', 'sound' => null, 'interruptionLevel' => 'passive'])
        ->and(json_encode([$criticalPayload, $warnPayload]))->not->toContain('ExponentPushToken', 'secret-path', 'access_token', 'affected_monitors');
})->group('AC-push-mobile-widget-status-3');

it('retries only transient failures and deactivates exactly the provider-named device through real workers', function (): void {
    $project = (string) Str::uuid();
    $operation = pushRuntimeOperation($project);
    $first = pushRuntimeDevice($project, 'ExponentPushToken[first]');
    $second = pushRuntimeDevice($project, 'ExponentPushToken[second]');
    ProcessNotificationIntent::dispatch($operation->operation_id);

    $deviceWorker = runPushRuntimeWorker($this->pushRuntimeDatabase, 'empty', 'deactivated:'.$first->public_id);
    expect($deviceWorker->isSuccessful())->toBeTrue($deviceWorker->getErrorOutput().$deviceWorker->getOutput())
        ->and($first->fresh()->active)->toBeFalse()
        ->and($second->fresh()->active)->toBeTrue()
        ->and(PushDeliveryAttempt::query()->where('operation_id', $operation->operation_id)->where('device_id', $first->public_id)->sole()->status)->toBe('deactivated')
        ->and(PushDeliveryAttempt::query()->where('operation_id', $operation->operation_id)->where('device_id', $second->public_id)->sole()->status)->toBe('accepted');

    $retryProject = (string) Str::uuid();
    $retryOperation = pushRuntimeOperation($retryProject, 'warn');
    $retryDevice = pushRuntimeDevice($retryProject, 'ExpoPushToken[retry]');
    ProcessNotificationIntent::dispatch($retryOperation->operation_id);
    $claimWorker = runPushRuntimeWorker($this->pushRuntimeDatabase, 'once');
    expect($claimWorker->isSuccessful())->toBeTrue($claimWorker->getErrorOutput().$claimWorker->getOutput());

    $retryWorker = runPushRuntimeWorker($this->pushRuntimeDatabase, 'once', 'retrying');
    $retryAttempt = PushDeliveryAttempt::query()->where('operation_id', $retryOperation->operation_id)->where('device_id', $retryDevice->public_id)->sole();
    $queuedRetry = DB::table('jobs')->sole();
    expect($retryWorker->isSuccessful())->toBeTrue($retryWorker->getErrorOutput().$retryWorker->getOutput())
        ->and($retryAttempt->status)->toBe('retrying')
        ->and($retryAttempt->provider_attempts)->toBe(1)
        ->and($retryAttempt->available_at->isFuture())->toBeTrue()
        ->and($retryAttempt->available_at->diffInSeconds(now()))->toBeLessThanOrEqual(6.0)
        ->and((int) $queuedRetry->available_at)->toBeGreaterThanOrEqual(time() + 3)
        ->and((int) $queuedRetry->available_at)->toBeLessThanOrEqual(time() + 7);

    sleep(6);
    $acceptedRetryWorker = runPushRuntimeWorker($this->pushRuntimeDatabase, 'once');
    expect($acceptedRetryWorker->isSuccessful())->toBeTrue($acceptedRetryWorker->getErrorOutput().$acceptedRetryWorker->getOutput())
        ->and($retryAttempt->fresh()->status)->toBe('accepted')
        ->and($retryAttempt->fresh()->provider_attempts)->toBe(2)
        ->and(DB::table('jobs')->count())->toBe(0);
})->group('AC-push-mobile-widget-status-2', 'AC-push-mobile-widget-status-3');

it('uses real workers for proving dispatch and requires 28 elapsed complete UTC days', function (): void {
    $project = (string) Str::uuid();
    pushRuntimeDevice($project, 'ExpoPushToken[proving]');
    $windowOpens = CarbonImmutable::parse('2026-07-11T12:00:00Z');
    for ($day = 0; $day < 28; $day++) {
        CarbonImmutable::setTestNow($windowOpens->addDays($day));
        $operation = pushRuntimeOperation($project, 'critical');
        ProcessNotificationIntent::dispatch($operation->operation_id);
        ProcessNotificationIntent::dispatch($operation->operation_id);
    }

    $provingWorker = runPushRuntimeWorker($this->pushRuntimeDatabase);
    expect($provingWorker->isSuccessful())->toBeTrue($provingWorker->getErrorOutput().$provingWorker->getOutput())
        ->and(PushDeliveryAttempt::query()->where('channel', 'expo')->where('status', 'accepted')->count())->toBe(28)
        ->and(PushDeliveryAttempt::query()->where('channel', 'legacy_webhook')->where('status', 'accepted')->count())->toBe(28)
        ->and(DB::table('push_reliability_receipts')->count())->toBe(56);

    CarbonImmutable::setTestNow('2026-08-07T12:00:00Z');
    $partialDay = app(PushReliabilityReadModel::class)->forProject(new ProjectIdentity($project));
    expect($partialDay['critical_intents'])->toBe(27)
        ->and($partialDay['consecutive_complete_days'])->toBe(27)
        ->and($partialDay['retirement_ready'])->toBeFalse();

    CarbonImmutable::setTestNow('2026-08-08T00:00:00Z');
    $ready = app(PushReliabilityReadModel::class)->forProject(new ProjectIdentity($project));
    expect($ready['critical_intents'])->toBe(28)
        ->and($ready['expo_accepted'])->toBe(28)
        ->and($ready['legacy_webhook_accepted'])->toBe(28)
        ->and($ready['failed_or_missing_pairs'])->toBe(0)
        ->and($ready['consecutive_complete_days'])->toBe(28)
        ->and($ready['retirement_ready'])->toBeTrue()
        ->and(DB::table('push_reliability_receipts')->count())->toBe(56);

    CarbonImmutable::setTestNow();
    $warn = pushRuntimeOperation($project, 'warn');
    ProcessNotificationIntent::dispatch($warn->operation_id);
    ProcessNotificationIntent::dispatch($warn->operation_id);
    $warnWorker = runPushRuntimeWorker($this->pushRuntimeDatabase);
    expect($warnWorker->isSuccessful())->toBeTrue($warnWorker->getErrorOutput().$warnWorker->getOutput())
        ->and(PushDeliveryAttempt::query()->where('operation_id', $warn->operation_id)->where('channel', 'expo')->count())->toBe(1)
        ->and(PushDeliveryAttempt::query()->where('operation_id', $warn->operation_id)->where('channel', 'legacy_webhook')->count())->toBe(0);

    $brokenProject = (string) Str::uuid();
    pushRuntimeDevice($brokenProject, 'ExpoPushToken[missing-pair]');
    $broken = pushRuntimeOperation($brokenProject, 'critical');
    $broken->forceFill(['created_at' => CarbonImmutable::parse('2026-08-07T13:00:00Z'), 'updated_at' => CarbonImmutable::parse('2026-08-07T13:00:00Z')])->save();
    ProcessNotificationIntent::dispatch($broken->operation_id);
    $brokenWorker = runPushRuntimeWorker($this->pushRuntimeDatabase, 'empty', 'accepted', '');
    CarbonImmutable::setTestNow('2026-08-08T00:00:00Z');
    $brokenReliability = app(PushReliabilityReadModel::class)->forProject(new ProjectIdentity($brokenProject));
    expect($brokenWorker->isSuccessful())->toBeTrue($brokenWorker->getErrorOutput().$brokenWorker->getOutput())
        ->and($brokenReliability['failed_or_missing_pairs'])->toBe(1)
        ->and($brokenReliability['retirement_ready'])->toBeFalse();
})->group('AC-push-mobile-widget-status-4');
