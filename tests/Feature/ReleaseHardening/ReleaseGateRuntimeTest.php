<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Push\Models\PushDevice;
use Symfony\Component\Process\Process;

function releaseGateRoot(): string
{
    return dirname(__DIR__, 3);
}

function releaseGatePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($socket === false) {
        throw new RuntimeException("Unable to reserve a release-gate port: {$errorCode} {$errorMessage}");
    }

    $address = (string) stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr($address, strrpos($address, ':') + 1);
}

/** @return array{directory: string, id: string, database: string, url: string} */
function startReleaseGateRuntime(): array
{
    $root = releaseGateRoot();
    $id = (string) Str::uuid();
    $directory = $root.'/build/harness-runs/'.$id;
    $database = $directory.'/database.sqlite';
    $port = releaseGatePort();
    $process = new Process([
        $root.'/scripts/runtime/backend', 'start', '--run-dir', $directory, '--port', (string) $port, '--timeout', '15',
    ], $root, [
        'HARNESS_DB_CONNECTION' => 'sqlite',
        'HARNESS_DB_DATABASE' => $database,
        'CHECKYBOT_EXPO_DELIVERY_FAKE' => 'accepted',
        'CHECKYBOT_LEGACY_WEBHOOK_FAKE' => 'accepted',
        'CHECKYBOT_PUSH_PROVING_ENABLED' => 'true',
        'CHECKYBOT_LEGACY_ALERT_WEBHOOK_URL' => 'https://legacy.example.test/redacted-runtime-secret',
    ]);
    $process->setTimeout(45);
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    return ['directory' => $directory, 'id' => $id, 'database' => $database, 'url' => "http://127.0.0.1:{$port}"];
}

/** @param array{directory: string, id: string, database: string, url: string} $runtime */
function stopReleaseGateRuntime(array $runtime): void
{
    DB::disconnect('sqlite');
    $process = new Process([
        releaseGateRoot().'/scripts/runtime/backend', 'stop', '--run-dir', $runtime['directory'],
    ], releaseGateRoot());
    $process->setTimeout(20);
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
}

/** @param array{directory: string, id: string, database: string, url: string} $runtime */
function runReleaseGateRelay(array $runtime): void
{
    $process = new Process([
        PHP_BINARY, releaseGateRoot().'/scripts/harness/artisan', 'checkybot:foundation-relay', '--limit=1000', '--no-interaction',
    ], releaseGateRoot(), [
        'APP_ENV' => 'harness',
        'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $runtime['database'],
        'QUEUE_CONNECTION' => 'database',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'HARNESS_RUN_ID' => $runtime['id'],
        'HARNESS_RUN_DIR' => $runtime['directory'],
        'CHECKYBOT_EXPO_DELIVERY_FAKE' => 'accepted',
        'CHECKYBOT_LEGACY_WEBHOOK_FAKE' => 'accepted',
        'CHECKYBOT_PUSH_PROVING_ENABLED' => 'true',
        'CHECKYBOT_LEGACY_ALERT_WEBHOOK_URL' => 'https://legacy.example.test/redacted-runtime-secret',
    ]);
    $process->setTimeout(30);
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
}

/**
 * @param  callable(array<string, mixed>): bool  $condition
 * @return array<string, mixed>
 */
function awaitReleaseGateJson(Client $client, string $path, callable $condition, int $attempts = 100): array
{
    $last = [];
    for ($attempt = 0; $attempt < $attempts; $attempt++) {
        $response = $client->get($path);
        $last = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        if ($response->getStatusCode() === 200 && $condition($last)) {
            return $last;
        }
        usleep(100_000);
    }

    throw new RuntimeException('Timed out awaiting release-gate receipt: '.json_encode($last));
}

/** @return array<string, mixed> */
function releaseGatePushPayload(string $project, string $monitor, string $operation, CarbonImmutable $observedAt, string $signal, float $value): array
{
    return [
        'operation_id' => $operation,
        'identity' => ['project_uuid' => $project, 'monitor_uuid' => $monitor, 'type' => 'server'],
        'source' => 'push',
        'observed_at' => $observedAt->toRfc3339String(),
        'signal' => $signal,
        'reason_code' => $signal === 'critical' ? 'threshold-exceeded' : 'threshold-recovered',
        'value' => $value,
        'thresholds' => ['warn' => 50, 'critical' => 80, 'recovery_delta' => 5],
    ];
}

beforeEach(function (): void {
    $this->releaseGateRuntimes = [];
});

afterEach(function (): void {
    foreach ($this->releaseGateRuntimes as $runtime) {
        if (is_file($runtime['directory'].'/run.id')) {
            stopReleaseGateRuntime($runtime);
        }
    }
});

it('proves a sub-thirty-second pull blip has no intent or delivery through the real runtime', function (): void {
    $runtime = startReleaseGateRuntime();
    $this->releaseGateRuntimes[] = $runtime;
    $client = new Client(['base_uri' => $runtime['url'], 'http_errors' => false, 'headers' => ['Accept' => 'application/json']]);
    $project = (string) Str::uuid();
    $monitor = (string) Str::uuid();
    $failedOperation = (string) Str::uuid();
    $recoveredOperation = (string) Str::uuid();
    $start = CarbonImmutable::now('UTC')->subSeconds(20);

    $failed = [
        'operation_id' => $failedOperation,
        'identity' => ['project_uuid' => $project, 'monitor_uuid' => $monitor, 'type' => 'website'],
        'source' => 'pull', 'observed_at' => $start->toRfc3339String(), 'signal' => 'failure', 'reason_code' => 'timeout',
    ];
    $recovered = [
        'operation_id' => $recoveredOperation,
        'identity' => ['project_uuid' => $project, 'monitor_uuid' => $monitor, 'type' => 'website'],
        'source' => 'pull', 'observed_at' => $start->addSeconds(20)->toRfc3339String(), 'signal' => 'success', 'reason_code' => null,
    ];

    expect($client->post('/__harness/alerting/results', ['json' => $failed])->getStatusCode())->toBe(202)
        ->and($client->post('/__harness/alerting/results', ['json' => $recovered])->getStatusCode())->toBe(202);

    awaitReleaseGateJson($client, '/__harness/alerting/receipts/'.$recoveredOperation, static fn (array $body): bool => ($body['status'] ?? null) === 'processed');
    runReleaseGateRelay($runtime);
    $receipt = awaitReleaseGateJson(
        $client,
        '/__harness/alerting/receipts/'.$failedOperation,
        static fn (array $body): bool => collect($body['consumer_receipts'] ?? [])->contains('effect', 'transition_persisted'),
    );
    $recoveryReceipt = awaitReleaseGateJson($client, '/__harness/alerting/receipts/'.$recoveredOperation, static fn (array $body): bool => ($body['status'] ?? null) === 'processed');

    expect($start->diffInSeconds($start->addSeconds(20)))->toBeLessThan(30)
        ->and($receipt['incident_groups'])->toBe([])
        ->and($receipt['notification_intents'])->toBe([])
        ->and($recoveryReceipt['incident_groups'])->toBe([])
        ->and($recoveryReceipt['notification_intents'])->toBe([]);

    foreach ([$failedOperation, $recoveredOperation] as $operation) {
        $push = $client->get('/__harness/push/receipts/'.$operation);
        expect($push->getStatusCode())->toBe(404)
            ->and(json_decode((string) $push->getBody(), true, flags: JSON_THROW_ON_ERROR))->toBe([
                'message' => 'Push operation not found.',
            ]);
    }
})->group('AC-release-hardening-1');

it('proves one grouped critical incident and recovery are dual-sent exactly once', function (): void {
    $runtime = startReleaseGateRuntime();
    $this->releaseGateRuntimes[] = $runtime;
    $client = new Client(['base_uri' => $runtime['url'], 'http_errors' => false, 'headers' => ['Accept' => 'application/json']]);
    $project = (string) Str::uuid();
    $monitors = [(string) Str::uuid(), (string) Str::uuid()];

    config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite', 'database' => $runtime['database'], 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 10000,
    ]);
    DB::purge('sqlite');
    PushDevice::query()->create([
        'user_id' => 'release-gate-user', 'installation_id' => (string) Str::uuid(), 'project_id' => $project,
        'platform' => 'ios', 'expo_push_token' => 'ExponentPushToken[release-gate]',
        'expo_token_hash' => hash('sha256', 'ExponentPushToken[release-gate]'), 'permission' => 'granted',
        'app_version' => '1.0.0', 'active' => true, 'registered_at' => now(),
    ]);

    $base = CarbonImmutable::now('UTC')->subSeconds(90);
    $criticalPayloads = [];
    foreach ($monitors as $monitorIndex => $monitor) {
        for ($sample = 0; $sample < 3; $sample++) {
            $operation = (string) Str::uuid();
            $payload = releaseGatePushPayload($project, $monitor, $operation, $base->addSeconds(($monitorIndex * 3) + $sample), 'critical', 95);
            $criticalPayloads[] = $payload;
            expect($client->post('/__harness/alerting/results', ['json' => $payload])->getStatusCode())->toBe(202);
        }
    }

    $criticalReceipt = awaitReleaseGateJson(
        $client,
        '/__harness/alerting/receipts/'.$criticalPayloads[5]['operation_id'],
        static fn (array $body): bool => count($body['notification_intents'] ?? []) === 1,
    );
    runReleaseGateRelay($runtime);
    $incidentIntent = $criticalReceipt['notification_intents'][0];
    $incidentPush = awaitReleaseGateJson(
        $client,
        '/__harness/push/receipts/'.$incidentIntent['operation_id'],
        static fn (array $body): bool => ($body['listener_status'] ?? null) === 'processed',
    );

    $recoveryPayloads = [];
    foreach ($monitors as $monitorIndex => $monitor) {
        for ($sample = 0; $sample < 3; $sample++) {
            $operation = (string) Str::uuid();
            $payload = releaseGatePushPayload($project, $monitor, $operation, $base->addSeconds(20 + ($monitorIndex * 3) + $sample), 'healthy', 40);
            $recoveryPayloads[] = $payload;
            expect($client->post('/__harness/alerting/results', ['json' => $payload])->getStatusCode())->toBe(202);
        }
    }

    $finalReceipt = awaitReleaseGateJson(
        $client,
        '/__harness/alerting/receipts/'.$recoveryPayloads[5]['operation_id'],
        static fn (array $body): bool => count($body['notification_intents'] ?? []) === 2,
    );
    runReleaseGateRelay($runtime);
    $intents = collect($finalReceipt['notification_intents'])->keyBy('phase');
    $recoveryIntent = $intents->get('recovery');
    $recoveryPush = awaitReleaseGateJson(
        $client,
        '/__harness/push/receipts/'.$recoveryIntent['operation_id'],
        static fn (array $body): bool => ($body['listener_status'] ?? null) === 'processed',
    );

    // Replaying accepted results and the relay must converge on the immutable operations.
    foreach ([...$criticalPayloads, ...$recoveryPayloads] as $payload) {
        expect($client->post('/__harness/alerting/results', ['json' => $payload])->getStatusCode())->toBe(200);
    }
    runReleaseGateRelay($runtime);

    expect($finalReceipt['incident_groups'])->toHaveCount(1)
        ->and($intents)->toHaveCount(2)
        ->and($intents->get('incident')['notification_thread_key'])->toBe($recoveryIntent['notification_thread_key'])
        ->and($intents->get('incident')['affected_monitors'])->toHaveCount(2)
        ->and($recoveryIntent['affected_monitors'])->toHaveCount(2);

    foreach ([$incidentPush, $recoveryPush] as $pushReceipt) {
        expect($pushReceipt['listener_status'])->toBe('processed')
            ->and($pushReceipt['legacy_webhook'])->toBe('accepted')
            ->and($pushReceipt['reliability_recorded'])->toBeTrue()
            ->and($pushReceipt['expo_deliveries'])->toHaveCount(1)
            ->and($pushReceipt['expo_deliveries'][0]['status'])->toBe('accepted');
    }
})->group('AC-release-hardening-2');
