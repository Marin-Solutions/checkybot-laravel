<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function harnessRoot(): string
{
    return dirname(__DIR__, 3);
}

function newHarnessRunDirectory(): string
{
    return harnessRoot().'/build/harness-runs/'.(string) Str::uuid();
}

function freeHarnessPort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

    if ($socket === false) {
        throw new RuntimeException("Unable to reserve a test port: {$errorCode} {$errorMessage}");
    }

    $address = stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr((string) $address, strrpos((string) $address, ':') + 1);
}

/** @param array<string, string> $environment */
function startHarness(string $runDirectory, int $port, array $environment = [], int $timeout = 30): Process
{
    $process = new Process([
        harnessRoot().'/scripts/runtime/backend',
        'start',
        '--run-dir',
        $runDirectory,
        '--port',
        (string) $port,
        '--timeout',
        (string) min($timeout, 15),
    ], harnessRoot(), array_merge([
        'HARNESS_DB_CONNECTION' => 'sqlite',
        'HARNESS_DB_DATABASE' => $runDirectory.'/database.sqlite',
    ], $environment));
    $process->setTimeout($timeout + 15);
    $process->run();

    return $process;
}

function stopHarness(string $runDirectory): Process
{
    $process = new Process([
        harnessRoot().'/scripts/runtime/backend',
        'stop',
        '--run-dir',
        $runDirectory,
    ], harnessRoot());
    $process->setTimeout(15);
    $process->run();

    return $process;
}

function harnessProcessIsOwned(string $runDirectory, string $role): bool
{
    $pidFile = "{$runDirectory}/{$role}.pid";

    if (! is_file($pidFile)) {
        return false;
    }

    $pid = (int) trim((string) file_get_contents($pidFile));
    $environmentFile = "/proc/{$pid}/environ";

    if ($pid < 2 || ! is_readable($environmentFile)) {
        return false;
    }

    $environment = (string) file_get_contents($environmentFile);
    $runId = trim((string) file_get_contents("{$runDirectory}/run.id"));

    return str_contains($environment, "HARNESS_RUN_ID={$runId}\0")
        && str_contains($environment, "HARNESS_RUN_DIR={$runDirectory}\0");
}

function awaitHarnessProcessStopped(string $runDirectory, string $role): void
{
    for ($attempt = 0; $attempt < 50; $attempt++) {
        if (! harnessProcessIsOwned($runDirectory, $role)) {
            return;
        }

        usleep(100_000);
    }

    expect(harnessProcessIsOwned($runDirectory, $role))->toBeFalse();
}

beforeEach(function (): void {
    $this->harnessRunDirectories = [];
});

afterEach(function (): void {
    foreach ($this->harnessRunDirectories as $runDirectory) {
        if (is_file($runDirectory.'/run.id')) {
            stopHarness($runDirectory);
        }
    }
});

it('starts a ready Laravel server and queue worker and stops only its recorded children', function (): void {
    $runDirectory = newHarnessRunDirectory();
    $this->harnessRunDirectories[] = $runDirectory;
    $port = freeHarnessPort();

    $start = startHarness($runDirectory, $port);

    expect($start->isSuccessful())->toBeTrue($start->getErrorOutput())
        ->and(harnessProcessIsOwned($runDirectory, 'app'))->toBeTrue()
        ->and(harnessProcessIsOwned($runDirectory, 'worker'))->toBeTrue();

    $client = new Client(['base_uri' => "http://127.0.0.1:{$port}", 'http_errors' => false]);
    $readyResponse = $client->get('/__harness/ready', ['headers' => ['Accept' => 'application/json']]);
    $ready = json_decode((string) $readyResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);

    expect($readyResponse->getStatusCode())->toBe(200)
        ->and($ready)->toMatchArray([
            'app' => 'ready',
            'queue' => 'ready',
            'run_id' => basename($runDirectory),
        ]);

    $unrelated = new Process(['php', '-r', 'sleep(30);']);
    $unrelated->start();

    try {
        expect($unrelated->isRunning())->toBeTrue();
        $stop = stopHarness($runDirectory);

        expect($stop->isSuccessful())->toBeTrue($stop->getErrorOutput())
            ->and($unrelated->isRunning())->toBeTrue();
        awaitHarnessProcessStopped($runDirectory, 'worker');
        awaitHarnessProcessStopped($runDirectory, 'app');
    } finally {
        $unrelated->stop(1);
    }
})->group('AC-test-harness-1');

it('processes a queue probe through the real HTTP API and real database worker', function (): void {
    $runDirectory = newHarnessRunDirectory();
    $this->harnessRunDirectories[] = $runDirectory;
    $port = freeHarnessPort();
    $start = startHarness($runDirectory, $port);

    expect($start->isSuccessful())->toBeTrue($start->getErrorOutput());

    $client = new Client([
        'base_uri' => "http://127.0.0.1:{$port}",
        'http_errors' => false,
        'headers' => ['Accept' => 'application/json'],
    ]);
    $probeId = (string) Str::uuid();
    $acceptedResponse = $client->post('/__harness/queue-probes', [
        'json' => ['probe_id' => $probeId],
    ]);
    $accepted = json_decode((string) $acceptedResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);

    expect($acceptedResponse->getStatusCode())->toBe(202)
        ->and($accepted)->toMatchArray(['status' => 'queued', 'probe_id' => $probeId])
        ->and($accepted['accepted_at'])->toBeString();

    $processed = null;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $statusResponse = $client->get("/__harness/queue-probes/{$probeId}");
        $processed = json_decode((string) $statusResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);

        if (($processed['status'] ?? null) === 'processed') {
            break;
        }

        usleep(100_000);
    }

    expect($processed)->toMatchArray(['status' => 'processed', 'probe_id' => $probeId])
        ->and($processed['processed_at'])->toBeString()->not->toBeEmpty();

    $duplicate = $client->post('/__harness/queue-probes', ['json' => ['probe_id' => $probeId]]);
    $duplicateBody = json_decode((string) $duplicate->getBody(), true, flags: JSON_THROW_ON_ERROR);

    expect($duplicate->getStatusCode())->toBe(422)
        ->and($duplicateBody)->toHaveKey('message')
        ->and($duplicateBody)->toHaveKey('errors.probe_id');
})->group('AC-test-harness-2');

it('fails occupied-port startup, cleans children, and retains the stage log', function (): void {
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    expect($listener)->not->toBeFalse();
    $address = stream_socket_get_name($listener, false);
    $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
    $runDirectory = newHarnessRunDirectory();
    $this->harnessRunDirectories[] = $runDirectory;

    try {
        $start = startHarness($runDirectory, $port);
    } finally {
        fclose($listener);
    }

    expect($start->isSuccessful())->toBeFalse()
        ->and(file_get_contents($runDirectory.'/app.log'))->toContain('[stage=occupied-port]')
        ->and(harnessProcessIsOwned($runDirectory, 'app'))->toBeFalse()
        ->and(harnessProcessIsOwned($runDirectory, 'worker'))->toBeFalse();
})->group('AC-test-harness-3');

it('fails app-readiness timeout, cleans children, and retains the stage log', function (): void {
    $runDirectory = newHarnessRunDirectory();
    $this->harnessRunDirectories[] = $runDirectory;
    $start = startHarness($runDirectory, freeHarnessPort(), ['HARNESS_TEST_APP_MODE' => 'timeout'], 2);

    expect($start->isSuccessful())->toBeFalse()
        ->and(file_get_contents($runDirectory.'/app.log'))->toContain('[stage=app-readiness-timeout]');
    awaitHarnessProcessStopped($runDirectory, 'app');
    expect(harnessProcessIsOwned($runDirectory, 'worker'))->toBeFalse();
})->group('AC-test-harness-3');

it('fails premature worker exit, cleans children, and retains the stage log', function (): void {
    $runDirectory = newHarnessRunDirectory();
    $this->harnessRunDirectories[] = $runDirectory;
    $start = startHarness($runDirectory, freeHarnessPort(), ['HARNESS_TEST_WORKER_MODE' => 'exit']);

    expect($start->isSuccessful())->toBeFalse()
        ->and(file_get_contents($runDirectory.'/worker.log'))->toContain('[stage=worker-premature-exit]');
    awaitHarnessProcessStopped($runDirectory, 'app');
    awaitHarnessProcessStopped($runDirectory, 'worker');
})->group('AC-test-harness-3');

it('isolates SQLite state per UUID run and refuses unsafe database configuration', function (): void {
    $runDirectory = newHarnessRunDirectory();
    $this->harnessRunDirectories[] = $runDirectory;
    $start = startHarness($runDirectory, freeHarnessPort());

    expect($start->isSuccessful())->toBeTrue($start->getErrorOutput())
        ->and(realpath($runDirectory.'/database.sqlite'))->toBe($runDirectory.'/database.sqlite')
        ->and(is_file($runDirectory.'/runtime.json'))->toBeTrue();

    $pdo = new PDO('sqlite:'.$runDirectory.'/database.sqlite');
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    expect($tables)->toContain('jobs', 'harness_queue_probes');

    $nonSqliteRun = newHarnessRunDirectory();
    $this->harnessRunDirectories[] = $nonSqliteRun;
    $nonSqlite = startHarness($nonSqliteRun, freeHarnessPort(), [
        'HARNESS_DB_CONNECTION' => 'mysql',
        'HARNESS_DB_DATABASE' => 'shared',
    ]);
    expect($nonSqlite->isSuccessful())->toBeFalse()
        ->and(file_get_contents($nonSqliteRun.'/runtime.log'))->toContain('[stage=database-validation]');

    $outsideRun = newHarnessRunDirectory();
    $this->harnessRunDirectories[] = $outsideRun;
    $outsideDatabase = harnessRoot().'/build/harness-runs/shared.sqlite';
    $outside = startHarness($outsideRun, freeHarnessPort(), [
        'HARNESS_DB_DATABASE' => $outsideDatabase,
    ]);
    expect($outside->isSuccessful())->toBeFalse()
        ->and(file_get_contents($outsideRun.'/runtime.log'))->toContain('[stage=database-validation]')
        ->and(is_file($outsideDatabase))->toBeFalse();
})->group('AC-test-harness-4');
