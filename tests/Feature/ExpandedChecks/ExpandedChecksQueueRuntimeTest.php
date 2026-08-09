<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\AlertingResult;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\PullRetryRequest;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Models\DomainExpiryObservation;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Models\ExpandedCheckEvaluation;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Models\ExpandedWebsiteMonitor;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Models\StoredCheckSpeedSample;
use MarinSolutions\CheckybotLaravel\Models\MonitorState;
use MarinSolutions\CheckybotLaravel\Models\MonitorTransition;
use Symfony\Component\Process\Process;

/** @param list<string> $arguments */
function expandedChecksRuntimeProcess(
    string $database,
    string $runId,
    string $runDirectory,
    string $lookupState,
    CarbonImmutable $now,
    array $arguments,
    ?string $barrier = null,
    ?string $ready = null,
): Process {
    $root = dirname(__DIR__, 3);
    $environment = [
        'APP_ENV' => 'testing',
        'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $database,
        'QUEUE_CONNECTION' => 'database',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'HARNESS_RUN_ID' => $runId,
        'HARNESS_RUN_DIR' => $runDirectory,
        'EXPANDED_LOOKUP_STATE' => $lookupState,
        'EXPANDED_TEST_NOW' => $now->toRfc3339String(),
    ];
    if ($barrier !== null && $ready !== null) {
        $environment['EXPANDED_WORKER_BARRIER'] = $barrier;
        $environment['EXPANDED_WORKER_READY'] = $ready;
    }

    $process = new Process([
        PHP_BINARY,
        __DIR__.'/Support/expanded-artisan',
        ...$arguments,
        '--no-interaction',
    ], $root, $environment);
    $process->setTimeout(45);

    return $process;
}

/** @param list<string> $arguments */
function runExpandedChecksRuntime(
    string $database,
    string $runId,
    string $runDirectory,
    string $lookupState,
    CarbonImmutable $now,
    array $arguments,
): Process {
    $process = expandedChecksRuntimeProcess($database, $runId, $runDirectory, $lookupState, $now, $arguments);
    $process->run();

    return $process;
}

beforeEach(function (): void {
    $root = dirname(__DIR__, 3);
    $directory = $root.'/build/expanded-check-runtime-tests';
    @mkdir($directory, 0777, true);
    $this->expandedRuntimeDatabase = $directory.'/'.Str::uuid().'.sqlite';
    $this->expandedRuntimeLookupState = $directory.'/'.Str::uuid().'.json';
    $this->expandedRuntimeRunId = (string) Str::uuid();
    $this->expandedRuntimeRunDirectory = $directory.'/'.$this->expandedRuntimeRunId;
    foreach (['bootstrap/cache', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $path) {
        @mkdir($this->expandedRuntimeRunDirectory.'/'.$path, 0777, true);
    }
    touch($this->expandedRuntimeDatabase);

    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => $this->expandedRuntimeDatabase,
        'prefix' => '',
        'foreign_key_constraints' => true,
        'busy_timeout' => 15000,
    ]);
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'connection' => 'sqlite',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
        'after_commit' => true,
    ]);
    config()->set('cache.default', 'array');
    DB::purge('sqlite');

    foreach ([
        '2026_08_06_000000_create_monitor_foundation_tables.php',
        '2026_08_06_010000_create_alerting_result_runtime_tables.php',
        '2026_08_06_010100_create_alerting_incident_group_tables.php',
        '2026_08_06_010200_create_maintenance_mode_tables.php',
        '2026_08_06_032000_create_expanded_website_check_tables.php',
    ] as $migration) {
        (include $root.'/database/migrations/'.$migration)->up();
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
    CarbonImmutable::setTestNow();
    DB::disconnect('sqlite');
    @unlink($this->expandedRuntimeDatabase);
    @unlink($this->expandedRuntimeLookupState);
});

it('performs fresh domain lookups through queue workers at the alerting +10 and +30 retries', function (): void {
    $start = CarbonImmutable::parse('2026-08-07T12:00:00Z');
    CarbonImmutable::setTestNow($start);
    $monitor = ExpandedWebsiteMonitor::domainExpiry((string) Str::uuid(), (string) Str::uuid(), 'Example.COM');
    DomainExpiryObservation::query()->create([
        'expanded_website_monitor_id' => $monitor->getKey(),
        'canonical_domain' => 'example.com',
        'expires_at' => $start->addYear(),
        'source' => 'whois',
        'fetched_at' => $start->subDay(),
    ]);
    file_put_contents($this->expandedRuntimeLookupState, json_encode([
        'responses' => ['timeout', 'no_expiry', 'success'],
        'expires_at' => $start->addYears(2)->toRfc3339String(),
        'calls' => [],
    ], JSON_THROW_ON_ERROR));

    $initialRefresh = runExpandedChecksRuntime(
        $this->expandedRuntimeDatabase,
        $this->expandedRuntimeRunId,
        $this->expandedRuntimeRunDirectory,
        $this->expandedRuntimeLookupState,
        $start,
        ['checkybot:expanded-refresh-domains'],
    );
    expect($initialRefresh->isSuccessful())->toBeTrue($initialRefresh->getErrorOutput()."\n".$initialRefresh->getOutput())
        ->and($initialRefresh->getOutput())->toContain('Queued 1 domain refresh job(s).');
    $firstWorker = runExpandedChecksRuntime(
        $this->expandedRuntimeDatabase,
        $this->expandedRuntimeRunId,
        $this->expandedRuntimeRunDirectory,
        $this->expandedRuntimeLookupState,
        $start,
        ['queue:work', 'database', '--stop-when-empty', '--sleep=1', '--tries=1', '--timeout=15'],
    );
    expect($firstWorker->isSuccessful())->toBeTrue($firstWorker->getErrorOutput()."\n".$firstWorker->getOutput());

    expect([
        'evaluations' => ExpandedCheckEvaluation::query()->count(),
        'results' => AlertingResult::query()->get(['signal', 'status'])->toArray(),
        'retries' => PullRetryRequest::query()->count(),
        'jobs' => DB::table('jobs')->count(),
    ])->toBe([
        'evaluations' => 1,
        'results' => [['signal' => 'failure', 'status' => 'processed']],
        'retries' => 1,
        'jobs' => 0,
    ], $firstWorker->getErrorOutput()."\n".$firstWorker->getOutput());
    $attemptTwo = PullRetryRequest::query()->where('attempt_number', 2)->sole();
    expect($attemptTwo->due_at->toRfc3339String())->toBe($start->addSeconds(10)->toRfc3339String())
        ->and(DomainExpiryObservation::query()->sole()->source)->toBe('whois')
        ->and(DomainExpiryObservation::query()->sole()->expires_at->toRfc3339String())->toBe($start->addYear()->toRfc3339String())
        ->and(DB::table('jobs')->count())->toBe(0);

    $retryTwo = runExpandedChecksRuntime(
        $this->expandedRuntimeDatabase,
        $this->expandedRuntimeRunId,
        $this->expandedRuntimeRunDirectory,
        $this->expandedRuntimeLookupState,
        $start->addSeconds(10),
        ['checkybot:alerting-retries'],
    );
    expect($retryTwo->isSuccessful())->toBeTrue($retryTwo->getErrorOutput()."\n".$retryTwo->getOutput())
        ->and($retryTwo->getOutput())->toContain('Dispatched 1 pull recheck(s).');
    $secondWorker = runExpandedChecksRuntime(
        $this->expandedRuntimeDatabase,
        $this->expandedRuntimeRunId,
        $this->expandedRuntimeRunDirectory,
        $this->expandedRuntimeLookupState,
        $start->addSeconds(10),
        ['queue:work', 'database', '--stop-when-empty', '--sleep=1', '--tries=1', '--timeout=15'],
    );
    expect($secondWorker->isSuccessful())->toBeTrue($secondWorker->getErrorOutput()."\n".$secondWorker->getOutput());

    $attemptThree = PullRetryRequest::query()->where('attempt_number', 3)->sole();
    expect($attemptThree->due_at->toRfc3339String())->toBe($start->addSeconds(30)->toRfc3339String())
        ->and(DomainExpiryObservation::query()->sole()->source)->toBe('whois')
        ->and(DomainExpiryObservation::query()->sole()->expires_at->toRfc3339String())->toBe($start->addYear()->toRfc3339String());
    $retryThree = runExpandedChecksRuntime(
        $this->expandedRuntimeDatabase,
        $this->expandedRuntimeRunId,
        $this->expandedRuntimeRunDirectory,
        $this->expandedRuntimeLookupState,
        $start->addSeconds(30),
        ['checkybot:alerting-retries'],
    );
    expect($retryThree->isSuccessful())->toBeTrue($retryThree->getErrorOutput()."\n".$retryThree->getOutput())
        ->and($retryThree->getOutput())->toContain('Dispatched 1 pull recheck(s).');
    $thirdWorker = runExpandedChecksRuntime(
        $this->expandedRuntimeDatabase,
        $this->expandedRuntimeRunId,
        $this->expandedRuntimeRunDirectory,
        $this->expandedRuntimeLookupState,
        $start->addSeconds(30),
        ['queue:work', 'database', '--stop-when-empty', '--sleep=1', '--tries=1', '--timeout=15'],
    );
    expect($thirdWorker->isSuccessful())->toBeTrue($thirdWorker->getErrorOutput()."\n".$thirdWorker->getOutput());

    /** @var array{calls: list<array{domain: string, at: string}>} $lookupState */
    $lookupState = json_decode((string) file_get_contents($this->expandedRuntimeLookupState), true, flags: JSON_THROW_ON_ERROR);
    expect($lookupState['calls'])->toBe([
        ['domain' => 'example.com', 'at' => $start->toRfc3339String()],
        ['domain' => 'example.com', 'at' => $start->addSeconds(10)->toRfc3339String()],
        ['domain' => 'example.com', 'at' => $start->addSeconds(30)->toRfc3339String()],
    ])->and(AlertingResult::query()->orderBy('observed_at')->pluck('signal')->all())->toBe(['failure', 'failure', 'success'])
        ->and(AlertingResult::query()->where('status', 'processed')->count())->toBe(3)
        ->and(PullRetryRequest::query()->whereNotNull('requested_at')->count())->toBe(2)
        ->and(DomainExpiryObservation::query()->sole()->source)->toBe('rdap')
        ->and(DomainExpiryObservation::query()->sole()->expires_at->toRfc3339String())->toBe($start->addYears(2)->toRfc3339String())
        ->and(DB::table('jobs')->count())->toBe(0);
})->group('AC-agent-v2-expanded-monitors-10');

it('runs all scheduled job types through real workers and deduplicates overlapping evaluator jobs', function (): void {
    $now = CarbonImmutable::parse('2026-08-07T00:00:00Z');
    $project = (string) Str::uuid();
    $domain = ExpandedWebsiteMonitor::domainExpiry($project, (string) Str::uuid(), 'scheduled.example');
    $response = ExpandedWebsiteMonitor::responseBudget($project, (string) Str::uuid());
    $disabledDomain = ExpandedWebsiteMonitor::domainExpiry($project, (string) Str::uuid(), 'disabled.example', false);
    $disabledResponse = ExpandedWebsiteMonitor::responseBudget($project, (string) Str::uuid(), false);
    DomainExpiryObservation::query()->create([
        'expanded_website_monitor_id' => $domain->getKey(),
        'canonical_domain' => 'scheduled.example',
        'expires_at' => $now->addDays(31),
        'source' => 'whois',
        'fetched_at' => $now,
    ]);
    StoredCheckSpeedSample::query()->create([
        'project_id' => $project,
        'check_id' => $response->monitor_id,
        'successful' => true,
        'speed_ms' => 2001,
        'observed_at' => $now->subMinute(),
    ]);
    file_put_contents($this->expandedRuntimeLookupState, json_encode([
        'responses' => ['success'],
        'expires_at' => $now->addYear()->toRfc3339String(),
        'calls' => [],
    ], JSON_THROW_ON_ERROR));

    $commands = [
        ['checkybot:expanded-refresh-domains'],
        ['checkybot:expanded-evaluate-domains'],
        ['checkybot:expanded-evaluate-response-budgets'],
    ];
    foreach ($commands as $arguments) {
        foreach ([1, 2] as $_duplicate) {
            $schedulerTarget = runExpandedChecksRuntime(
                $this->expandedRuntimeDatabase,
                $this->expandedRuntimeRunId,
                $this->expandedRuntimeRunDirectory,
                $this->expandedRuntimeLookupState,
                $now,
                $arguments,
            );
            expect($schedulerTarget->isSuccessful())->toBeTrue($schedulerTarget->getErrorOutput()."\n".$schedulerTarget->getOutput());
        }
    }

    expect(DB::table('jobs')->count())->toBe(6)
        ->and(ExpandedCheckEvaluation::query()->count())->toBe(0)
        ->and(AlertingResult::query()->count())->toBe(0)
        ->and(MonitorState::query()->count())->toBe(0)
        ->and(MonitorTransition::query()->count())->toBe(0);

    $responseJobs = DB::table('jobs')->orderBy('id')->get()->filter(function (object $job): bool {
        /** @var array{displayName?: string} $payload */
        $payload = json_decode((string) $job->payload, true, flags: JSON_THROW_ON_ERROR);

        return ($payload['displayName'] ?? '') === 'MarinSolutions\\CheckybotLaravel\\Domain\\ExpandedChecks\\Jobs\\EvaluateResponseBudgetJob';
    })->values();
    expect($responseJobs)->toHaveCount(2);
    DB::table('jobs')->where('id', $responseJobs[0]->id)->update(['queue' => 'expanded-race-0']);
    DB::table('jobs')->where('id', $responseJobs[1]->id)->update(['queue' => 'expanded-race-1']);

    $root = dirname(__DIR__, 3);
    $barrier = $root.'/build/expanded-check-runtime-tests/barrier-'.Str::uuid();
    $readyFiles = [$barrier.'-ready-0', $barrier.'-ready-1'];
    $workers = [];
    foreach ([0, 1] as $index) {
        $worker = expandedChecksRuntimeProcess(
            $this->expandedRuntimeDatabase,
            $this->expandedRuntimeRunId,
            $this->expandedRuntimeRunDirectory,
            $this->expandedRuntimeLookupState,
            $now,
            ['queue:work', 'database', '--queue=expanded-race-'.$index, '--stop-when-empty', '--sleep=1', '--tries=1', '--timeout=15'],
            $barrier,
            $readyFiles[$index],
        );
        $worker->start();
        $workers[] = $worker;
    }
    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline) {
        clearstatcache();
        if (count(array_filter($readyFiles, 'is_file')) === 2) {
            break;
        }
        usleep(20_000);
    }
    expect(count(array_filter($readyFiles, 'is_file')))->toBe(2);
    touch($barrier);
    foreach ($workers as $worker) {
        $worker->wait();
        expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput()."\n".$worker->getOutput());
    }
    foreach ([$barrier, ...$readyFiles] as $file) {
        @unlink($file);
    }

    expect(ExpandedCheckEvaluation::query()->where('kind', 'response_budget')->count())->toBe(1)
        ->and(AlertingResult::query()->where('monitor_id', $response->monitor_id)->count())->toBe(1);

    $defaultWorker = runExpandedChecksRuntime(
        $this->expandedRuntimeDatabase,
        $this->expandedRuntimeRunId,
        $this->expandedRuntimeRunDirectory,
        $this->expandedRuntimeLookupState,
        $now,
        ['queue:work', 'database', '--queue=default', '--stop-when-empty', '--sleep=1', '--tries=1', '--timeout=15'],
    );
    expect($defaultWorker->isSuccessful())->toBeTrue($defaultWorker->getErrorOutput()."\n".$defaultWorker->getOutput());

    /** @var array{calls: list<array{domain: string, at: string}>} $lookupState */
    $lookupState = json_decode((string) file_get_contents($this->expandedRuntimeLookupState), true, flags: JSON_THROW_ON_ERROR);
    expect(ExpandedCheckEvaluation::query()->count())->toBe(3)
        ->and(ExpandedCheckEvaluation::query()->where('status', 'submitted')->count())->toBe(3)
        ->and(AlertingResult::query()->count())->toBe(3)
        ->and(AlertingResult::query()->where('status', 'processed')->count())->toBe(3)
        ->and($lookupState['calls'])->toHaveCount(1)
        ->and(ExpandedCheckEvaluation::query()->where('expanded_website_monitor_id', $disabledDomain->getKey())->count())->toBe(0)
        ->and(ExpandedCheckEvaluation::query()->where('expanded_website_monitor_id', $disabledResponse->getKey())->count())->toBe(0)
        ->and(MonitorState::query()->count())->toBe(2)
        ->and(MonitorTransition::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
})->group('AC-agent-v2-expanded-monitors-13');
