<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\AgentMonitorEvaluation;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\AgentServerLiveness;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\RegisteredServer;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\AlertingResult;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $directory = dirname(__DIR__, 3).'/build/agent-dead-man-tests';
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    $this->deadManDatabase = $directory.'/'.Str::uuid().'.sqlite';
    $this->deadManRunId = (string) Str::uuid();
    $this->deadManRunDirectory = $directory.'/'.$this->deadManRunId;
    foreach (['bootstrap/cache', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $path) {
        mkdir($this->deadManRunDirectory.'/'.$path, 0777, true);
    }
    touch($this->deadManDatabase);
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite', 'database' => $this->deadManDatabase, 'prefix' => '',
        'foreign_key_constraints' => true, 'busy_timeout' => 10000,
    ]);
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', [
        'driver' => 'database', 'connection' => 'sqlite', 'table' => 'jobs',
        'queue' => 'default', 'retry_after' => 90, 'after_commit' => true,
    ]);
    DB::purge('sqlite');
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_000000_create_monitor_foundation_tables.php')->up();
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_010000_create_alerting_result_runtime_tables.php')->up();
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_030000_create_agent_v2_report_runtime_tables.php')->up();
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_031000_create_agent_monitor_evaluator_tables.php')->up();
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
    @unlink($this->deadManDatabase);
});

it('deduplicates barrier-synchronized overlapping dead-man scheduler scans', function (): void {
    $start = CarbonImmutable::parse('2026-08-07T12:00:00Z');
    $scanAt = $start->addSeconds(300);
    $server = RegisteredServer::register((string) Str::uuid());
    AgentServerLiveness::query()->create([
        'agent_server_id' => $server->getKey(),
        'project_id' => $server->project_id,
        'last_accepted_observed_at' => $start,
        'reporting_interval_seconds' => 60,
    ]);

    $root = dirname(__DIR__, 3);
    $barrier = $root.'/build/agent-dead-man-tests/barrier-'.Str::uuid();
    $processes = [];
    $readyFiles = [];
    $environment = [
        'APP_ENV' => 'testing',
        'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $this->deadManDatabase,
        'QUEUE_CONNECTION' => 'database',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'HARNESS_RUN_ID' => $this->deadManRunId,
        'HARNESS_RUN_DIR' => $this->deadManRunDirectory,
    ];
    foreach ([0, 1] as $index) {
        $ready = $barrier.'-ready-'.$index;
        $readyFiles[] = $ready;
        $process = new Process([
            PHP_BINARY,
            __DIR__.'/Support/dead-man-racer.php',
            $root,
            $barrier,
            $ready,
            $scanAt->toRfc3339String(),
        ], $root, $environment);
        $process->setTimeout(30);
        $process->start();
        $processes[] = $process;
    }

    $deadline = microtime(true) + 10;
    while (count(array_filter($readyFiles, 'is_file')) !== 2 && microtime(true) < $deadline) {
        usleep(20_000);
    }
    expect(count(array_filter($readyFiles, 'is_file')))->toBe(2);
    touch($barrier);
    foreach ($processes as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
    }
    foreach ([$barrier, ...$readyFiles] as $file) {
        @unlink($file);
    }

    expect(AgentMonitorEvaluation::query()->where('observation_kind', 'dead_man')->count())->toBe(1)
        ->and(AlertingResult::query()->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1);
})->group('AC-agent-v2-expanded-monitors-8');
