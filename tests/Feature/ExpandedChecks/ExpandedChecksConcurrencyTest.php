<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\AlertingResult;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Models\ExpandedCheckEvaluation;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Models\ExpandedWebsiteMonitor;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Models\StoredCheckSpeedSample;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $directory = dirname(__DIR__, 3).'/build/expanded-check-tests';
    @mkdir($directory, 0777, true);
    $this->expandedDatabase = $directory.'/'.Str::uuid().'.sqlite';
    $this->expandedRunId = (string) Str::uuid();
    $this->expandedRunDirectory = $directory.'/'.$this->expandedRunId;
    foreach (['bootstrap/cache', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $path) {
        @mkdir($this->expandedRunDirectory.'/'.$path, 0777, true);
    }
    touch($this->expandedDatabase);
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite', 'database' => $this->expandedDatabase, 'prefix' => '',
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
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_032000_create_expanded_website_check_tables.php')->up();
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
    @unlink($this->expandedDatabase);
});

it('deduplicates barrier-synchronized overlapping p95 evaluator processes', function (): void {
    $at = CarbonImmutable::parse('2026-08-07T12:00:00Z');
    $monitor = ExpandedWebsiteMonitor::responseBudget((string) Str::uuid(), (string) Str::uuid());
    StoredCheckSpeedSample::query()->create([
        'project_id' => $monitor->project_id, 'check_id' => $monitor->monitor_id,
        'successful' => true, 'speed_ms' => 2001, 'observed_at' => $at->subMinute(),
    ]);

    $root = dirname(__DIR__, 3);
    $barrier = $root.'/build/expanded-check-tests/barrier-'.Str::uuid();
    $processes = [];
    $readyFiles = [];
    $environment = [
        'APP_ENV' => 'testing', 'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->expandedDatabase,
        'QUEUE_CONNECTION' => 'database', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
        'HARNESS_RUN_ID' => $this->expandedRunId, 'HARNESS_RUN_DIR' => $this->expandedRunDirectory,
    ];
    foreach ([0, 1] as $index) {
        $ready = $barrier.'-ready-'.$index;
        $readyFiles[] = $ready;
        $process = new Process([PHP_BINARY, __DIR__.'/Support/expanded-evaluation-racer.php', $root, $barrier, $ready, (string) $monitor->getKey(), $at->toRfc3339String()], $root, $environment);
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

    expect(ExpandedCheckEvaluation::query()->count())->toBe(1)
        ->and(AlertingResult::query()->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1);
})->group('AC-agent-v2-expanded-monitors-13');
