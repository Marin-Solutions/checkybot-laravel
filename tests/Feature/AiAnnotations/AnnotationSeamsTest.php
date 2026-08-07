<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\RegisteredServer;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\AiAnnotationsServiceProvider;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Delivery\AiAnnotationOutboxDispatcher;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiAnnotationOperation;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiBudgetReservation;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiIncidentAnnotation;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiProjectSetting;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\FoundationContract;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery\FoundationEventDispatcher;
use MarinSolutions\CheckybotLaravel\Jobs\AiAnnotations\GenerateIncidentAnnotation as GenerateIncidentAnnotationJob;
use MarinSolutions\CheckybotLaravel\Models\MonitorState;
use MarinSolutions\CheckybotLaravel\Models\MonitorTransition;
use MarinSolutions\CheckybotLaravel\Models\OutboxEvent;
use MarinSolutions\CheckybotLaravel\Models\ProjectApiToken;
use Symfony\Component\Process\Process;

function aiSeamOperator(string $project): GenericUser
{
    return new GenericUser(['id' => 'ai-seam-operator', 'current_project_id' => $project, 'project_ids' => [$project]]);
}

/** @return array{0: MonitorTransition, 1: OutboxEvent} */
function aiSeamTransition(string $project, string $monitor, string $to = 'down', string $type = 'server', ?array $payloadIdentity = null): array
{
    $operation = (string) Str::uuid();
    $occurred = CarbonImmutable::now('UTC')->subMinute();
    $from = $to === 'healthy' ? 'recovering' : 'warn';
    $transition = MonitorTransition::query()->create([
        'project_id' => $project,
        'monitor_id' => $monitor,
        'monitor_type' => $type,
        'operation_id' => $operation,
        'operation_sequence' => MonitorTransition::query()->where('project_id', $project)->where('monitor_id', $monitor)->count() + 1,
        'from_state' => $from,
        'to_state' => $to,
        'severity' => $to === 'down' ? 'critical' : 'warn',
        'reason_code' => 'static-threshold-confirmed',
        'monitor_filter' => ['types' => [$type], 'states' => [$to], 'severities' => [$to === 'down' ? 'critical' : 'warn']],
        'occurred_at' => $occurred,
        'entered_at' => $occurred,
        'maintenance_suppressed' => false,
    ]);
    $identity = $payloadIdentity ?? ['project_id' => $project, 'monitor_id' => $monitor, 'type' => $type];
    $event = OutboxEvent::query()->create([
        'operation_id' => $operation,
        'event_type' => 'monitor.transitioned',
        'contract_version' => FoundationContract::VERSION,
        'payload' => [
            'contract_version' => FoundationContract::VERSION,
            'identity' => $identity,
            'from_state' => $from,
            'to_state' => $to,
            'severity' => $to === 'down' ? 'critical' : 'warn',
            'filter' => ['types' => [$type], 'states' => [$to]],
            'occurred_at' => $occurred->toRfc3339String(),
        ],
        'status' => 'pending',
        'available_at' => now(),
    ]);

    return [$transition, $event];
}

/** @return array<string, mixed> */
function aiSeamAgentPayload(string $server, CarbonImmutable $observedAt): array
{
    return [
        'schema_version' => 'agent-report.v2',
        'operation_id' => (string) Str::uuid(),
        'agent_version' => '2.5.0',
        'server_uuid' => $server,
        'observed_at' => $observedAt->format('Y-m-d\TH:i:s.u\Z'),
        'reporting_interval_seconds' => 60,
        'cpu' => ['five_min_percent' => 99.0],
        'memory' => ['used_percent' => 99.0],
        'disks' => [['mount' => '/', 'used_percent' => 95.0, 'predicted_days_to_full' => 2.0]],
        'network_interfaces' => [[
            'name' => 'eth0', 'rx_bytes_total' => 2000, 'tx_bytes_total' => 3000,
            'rx_delta_bytes' => 1000, 'tx_delta_bytes' => 1500, 'elapsed_seconds' => 1.0, 'sample_status' => 'ready',
        ]],
        'php_fpm_pools' => [['pool' => 'www', 'active_workers' => 20, 'max_children' => 20, 'max_children_reached_5m' => 1]],
        'nginx_window' => ['window_seconds' => 300, 'total_requests' => 100, 'five_xx_count' => 20, 'upstream_timeout_count' => 5],
        'prerequisites' => [
            ['kind' => 'nginx_access_log', 'path_hint' => '/var/log/nginx/access.log', 'status' => 'readable'],
            ['kind' => 'php_fpm_status', 'path_hint' => '/run/php/status', 'status' => 'readable'],
        ],
        'relevant_log_lines' => [[
            'source' => 'nginx',
            'observed_at' => $observedAt->format('Y-m-d\TH:i:s.u\Z'),
            'line' => 'upstream timed out user=[REDACTED] client=[REDACTED] token=[REDACTED]',
        ]],
    ];
}

/** @return array{0: Process, 1: string, 2: string} */
function runAiAnnotationWorker(string $database, string $scenario = 'valid'): array
{
    $root = dirname(__DIR__, 3);
    $directory = $root.'/build/ai-annotation-seam-tests';
    $token = (string) Str::uuid();
    $requestLog = $directory.'/'.$token.'-requests.log';
    $callLog = $directory.'/'.$token.'-calls.log';
    $process = new Process([
        PHP_BINARY, __DIR__.'/Support/ai-annotation-worker.php', $root, $database, $scenario, $requestLog, $callLog,
    ], $root, [
        'APP_ENV' => 'testing', 'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'QUEUE_CONNECTION' => 'database',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    ]);
    $process->setTimeout(60);
    $process->run();

    return [$process, $requestLog, $callLog];
}

function runAiSeamWorker(string $database, array $extraEnvironment = []): Process
{
    $root = dirname(__DIR__, 3);
    $process = new Process([
        PHP_BINARY, dirname(__DIR__).'/MonitoringFoundation/Support/queue-worker.php', $root, $database,
    ], $root, [
        'APP_ENV' => 'testing',
        'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $database,
        'QUEUE_CONNECTION' => 'database',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        ...$extraEnvironment,
    ]);
    $process->setTimeout(60);
    $process->run();

    return $process;
}

beforeEach(function (): void {
    $directory = dirname(__DIR__, 3).'/build/ai-annotation-seam-tests';
    @mkdir($directory, 0777, true);
    $this->aiSeamDatabase = $directory.'/'.Str::uuid().'.sqlite';
    touch($this->aiSeamDatabase);
    config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite', 'database' => $this->aiSeamDatabase, 'prefix' => '',
        'foreign_key_constraints' => true, 'busy_timeout' => 15000,
    ]);
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', [
        'driver' => 'database', 'connection' => 'sqlite', 'table' => 'jobs', 'queue' => 'default',
        'retry_after' => 90, 'after_commit' => true,
    ]);
    config()->set('ai-annotations.provider', [
        'url' => 'https://provider.example.test/v1/annotations', 'credential' => 'provider-secret',
        'model' => 'bounded-model', 'connect_timeout_seconds' => 2, 'timeout_seconds' => 5,
        'max_response_bytes' => 65536, 'harness_fake' => true,
        'harness_root_cause' => 'Upstream saturation for operator@example.test at 192.0.2.19 caused the outage.',
        'harness_billed_microusd' => 10,
    ]);
    config()->set('ai-annotations.budget', [
        'global_monthly_limit_microusd' => 1000, 'project_monthly_limit_microusd' => 1000,
        'max_request_microusd' => 100, 'input_token_microusd' => 1, 'output_token_microusd' => 1,
    ]);
    DB::purge('sqlite');
    foreach ([
        '2026_08_06_000000_create_monitor_foundation_tables.php',
        '2026_08_06_010000_create_alerting_result_runtime_tables.php',
        '2026_08_06_010100_create_alerting_incident_group_tables.php',
        '2026_08_06_020000_create_push_delivery_tables.php',
        '2026_08_06_030000_create_agent_v2_report_runtime_tables.php',
        '2026_08_06_031000_create_agent_monitor_evaluator_tables.php',
        '2026_08_06_900000_create_ai_annotation_tables.php',
        '2026_08_06_900100_add_side_effect_snapshot_to_ai_annotation_operations.php',
    ] as $migration) {
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
    @unlink($this->aiSeamDatabase);
});

it('registers and dispatches only one eligible confirmed server-down annotation job', function (): void {
    Queue::fake();
    $project = (string) Str::uuid();
    AiProjectSetting::query()->create(['project_id' => $project, 'enabled' => true]);
    $dispatcher = app(FoundationEventDispatcher::class);
    expect(app()->getProvider(AiAnnotationsServiceProvider::class))->toBeInstanceOf(AiAnnotationsServiceProvider::class)
        ->and(class_exists(AiAnnotationOutboxDispatcher::class))->toBeTrue()
        ->and(class_exists(GenerateIncidentAnnotationJob::class))->toBeTrue()
        ->and(route('checkybot.ai-annotations.settings.show'))->toContain('/checkybot/ai-annotations/settings')
        ->and(route('checkybot.ai-annotations.harness.receipts.show', ['operation_id' => (string) Str::uuid()]))
        ->toContain('/__harness/ai-annotations/receipts/');

    [, $eligible] = aiSeamTransition($project, (string) Str::uuid());
    $receipts = $dispatcher->dispatch($eligible, $eligible->payload);
    expect(collect($receipts)->firstWhere('consumer', 'ai-incident-annotations')['consumer_ack'])->toBe('queued');
    Queue::assertPushed(GenerateIncidentAnnotationJob::class, 1);

    $disabledProject = (string) Str::uuid();
    AiProjectSetting::query()->create(['project_id' => $disabledProject, 'enabled' => false]);
    [, $disabled] = aiSeamTransition($disabledProject, (string) Str::uuid());
    $dispatcher->dispatch($disabled, $disabled->payload);
    foreach (['warn', 'recovering', 'healthy'] as $state) {
        [, $event] = aiSeamTransition($project, (string) Str::uuid(), $state);
        $dispatcher->dispatch($event, $event->payload);
    }
    [, $website] = aiSeamTransition($project, (string) Str::uuid(), 'down', 'website');
    $dispatcher->dispatch($website, $website->payload);

    $missing = OutboxEvent::query()->create([
        'operation_id' => (string) Str::uuid(), 'event_type' => 'monitor.transitioned',
        'contract_version' => FoundationContract::VERSION, 'status' => 'pending', 'available_at' => now(),
        'payload' => [
            'contract_version' => FoundationContract::VERSION,
            'identity' => ['project_id' => $project, 'monitor_id' => (string) Str::uuid(), 'type' => 'server'],
            'from_state' => 'warn', 'to_state' => 'down', 'severity' => 'critical',
            'filter' => [], 'occurred_at' => now()->toRfc3339String(),
        ],
    ]);
    $dispatcher->dispatch($missing, $missing->payload);
    [, $mismatched] = aiSeamTransition($project, (string) Str::uuid(), 'down', 'server', [
        'project_id' => $project, 'monitor_id' => (string) Str::uuid(), 'type' => 'server',
    ]);
    $dispatcher->dispatch($mismatched, $mismatched->payload);
    $dispatcher->dispatch($eligible, $eligible->payload);

    Queue::assertPushed(GenerateIncidentAnnotationJob::class, 1);
    expect(AiAnnotationOperation::query()->count())->toBe(1);

    $environment = app('env');
    app()->instance('env', 'production');
    try {
        $this->getJson('/__harness/ai-annotations/receipts/'.$eligible->operation_id)->assertNotFound();
    } finally {
        app()->instance('env', $environment);
    }
    Queue::assertPushed(GenerateIncidentAnnotationJob::class, 1);
})->group('AC-ai-incident-annotations-6');

it('uses authorized bounded snippets and stores one recursively redacted transition annotation', function (): void {
    $project = (string) Str::uuid();
    $monitor = (string) Str::uuid();
    AiProjectSetting::query()->create(['project_id' => $project, 'enabled' => true]);
    [$transition] = aiSeamTransition($project, $monitor);
    AiAnnotationOperation::query()->create([
        'operation_id' => $transition->operation_id, 'transition_operation_id' => $transition->operation_id,
        'project_id' => $project, 'status' => 'queued', 'notification_side_effects' => ['before' => [], 'after' => null],
    ]);
    GenerateIncidentAnnotationJob::dispatch($transition->operation_id);
    GenerateIncidentAnnotationJob::dispatch($transition->operation_id);
    [$worker, $requestLog, $callLog] = runAiAnnotationWorker($this->aiSeamDatabase);
    expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput().$worker->getOutput());
    $requests = array_values(array_filter(explode("\n", trim((string) file_get_contents($requestLog)))));
    $calls = array_values(array_filter(explode("\n", trim((string) file_get_contents($callLog)))));
    $request = json_decode($requests[0], true, flags: JSON_THROW_ON_ERROR);
    $annotation = AiIncidentAnnotation::query()->sole();
    expect($requests)->toHaveCount(1)
        ->and($calls)->toHaveCount(1)
        ->and($request['authorized_project_id'])->toBe($project)
        ->and($request['project_id'])->toBe($project)
        ->and($request['monitor_id'])->toBe($monitor)
        ->and($request['type'])->toBe('server')
        ->and($request['sources'])->toBe(['nginx', 'fpm', 'mysql'])
        ->and($request['limit'])->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(200)
        ->and(CarbonImmutable::parse($request['from'])->lessThan($transition->occurred_at))->toBeTrue()
        ->and(CarbonImmutable::parse($request['to'])->greaterThanOrEqualTo($transition->occurred_at))->toBeTrue()
        ->and($annotation->transition_operation_id)->toBe($transition->operation_id)
        ->and($annotation->root_cause)->not->toContain('operator@example.test', '192.0.2.19')
        ->and(AiBudgetReservation::query()->count())->toBe(1)
        ->and(AiAnnotationOperation::query()->sole()->status)->toBe('completed');
    @unlink($requestLog);
    @unlink($callLog);
})->group('AC-ai-incident-annotations-7');

it('rejects alert-eligible snippet context through the real queue worker', function (): void {
    $project = (string) Str::uuid();
    [$transition] = aiSeamTransition($project, (string) Str::uuid());
    AiProjectSetting::query()->create(['project_id' => $project, 'enabled' => true]);
    AiAnnotationOperation::query()->create([
        'operation_id' => $transition->operation_id, 'transition_operation_id' => $transition->operation_id,
        'project_id' => $project, 'status' => 'queued', 'notification_side_effects' => ['before' => [], 'after' => null],
    ]);
    GenerateIncidentAnnotationJob::dispatch($transition->operation_id);
    [$worker, $requestLog, $callLog] = runAiAnnotationWorker($this->aiSeamDatabase, 'alert_eligible');
    expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput().$worker->getOutput())
        ->and(AiAnnotationOperation::query()->sole()->status)->toBe('skipped')
        ->and(AiAnnotationOperation::query()->sole()->skip_reason)->toBe('alert_eligible')
        ->and(AiIncidentAnnotation::query()->count())->toBe(0)
        ->and(AiBudgetReservation::query()->count())->toBe(0)
        ->and(is_file($callLog))->toBeFalse();
    @unlink($requestLog);
})->group('AC-ai-incident-annotations-7');

it('allows only one provider call and charge under barrier-synchronized process concurrency', function (): void {
    $project = (string) Str::uuid();
    $monitor = (string) Str::uuid();
    AiProjectSetting::query()->create(['project_id' => $project, 'enabled' => true]);
    [$transition] = aiSeamTransition($project, $monitor);
    AiAnnotationOperation::query()->create([
        'operation_id' => $transition->operation_id, 'transition_operation_id' => $transition->operation_id,
        'project_id' => $project, 'status' => 'queued', 'notification_side_effects' => ['before' => [], 'after' => null],
    ]);

    GenerateIncidentAnnotationJob::dispatch($transition->operation_id);
    GenerateIncidentAnnotationJob::dispatch($transition->operation_id);

    $root = dirname(__DIR__, 3);
    $directory = $root.'/build/ai-annotation-seam-tests';
    $barrier = $directory.'/barrier-'.Str::uuid();
    $callLog = $barrier.'-calls';
    $runDirectory = $directory.'/run-'.Str::uuid();
    foreach (['bootstrap/cache', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $path) {
        @mkdir($runDirectory.'/'.$path, 0777, true);
    }
    $environment = [
        'APP_ENV' => 'testing', 'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->aiSeamDatabase,
        'QUEUE_CONNECTION' => 'database',
        'AI_ANNOTATIONS_GLOBAL_MONTHLY_LIMIT_MICROUSD' => '1000',
        'AI_ANNOTATIONS_PROJECT_MONTHLY_LIMIT_MICROUSD' => '1000',
        'AI_ANNOTATIONS_MAX_REQUEST_MICROUSD' => '100',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
        'HARNESS_RUN_ID' => basename($runDirectory), 'HARNESS_RUN_DIR' => $runDirectory,
    ];
    $processes = [];
    $files = [];
    foreach ([0, 1] as $index) {
        $ready = $barrier.'-ready-'.$index;
        $result = $barrier.'-result-'.$index;
        $files[] = $ready;
        $files[] = $result;
        $process = new Process([
            PHP_BINARY, __DIR__.'/Support/annotation-job-racer.php', $root, $barrier, $ready, $result,
            $transition->operation_id, $callLog, (string) $index,
        ], $root, $environment);
        $process->setTimeout(40);
        $process->start();
        $processes[] = $process;
    }
    $deadline = microtime(true) + 15;
    while (count(array_filter([$barrier.'-ready-0', $barrier.'-ready-1'], 'is_file')) !== 2 && microtime(true) < $deadline) {
        usleep(20_000);
    }
    expect(is_file($barrier.'-ready-0'))->toBeTrue()->and(is_file($barrier.'-ready-1'))->toBeTrue();
    touch($barrier);
    foreach ($processes as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
    }

    $calls = is_file($callLog) ? array_values(array_filter(explode("\n", trim((string) file_get_contents($callLog))))) : [];
    expect($calls)->toHaveCount(1)
        ->and(AiIncidentAnnotation::query()->count())->toBe(1)
        ->and(AiBudgetReservation::query()->where('state', 'settled')->count())->toBe(1)
        ->and(AiAnnotationOperation::query()->sole()->status)->toBe('completed');
    foreach ([$barrier, $callLog, ...$files] as $file) {
        @unlink($file);
    }
})->group('AC-ai-incident-annotations-7');

it('does not mutate alert lifecycle notification or push rows on successful and failed AI processing', function (): void {
    $project = (string) Str::uuid();
    $monitor = (string) Str::uuid();
    AiProjectSetting::query()->create(['project_id' => $project, 'enabled' => true]);
    [$transition] = aiSeamTransition($project, $monitor);
    MonitorState::query()->create([
        'project_id' => $project, 'monitor_id' => $monitor, 'monitor_type' => 'server',
        'state' => 'down', 'severity' => 'critical', 'observed_at' => now(), 'entered_at' => now(),
    ]);
    $group = (string) Str::uuid();
    DB::table('alerting_incident_groups')->insert([
        'public_id' => $group, 'project_id' => $project, 'severity' => 'critical', 'notification_thread_key' => 'incident:'.$group,
        'opened_at' => now(), 'latest_member_at' => now(), 'collection_due_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $intent = (string) Str::uuid();
    DB::table('alerting_notification_intents')->insert([
        'public_id' => $intent, 'operation_id' => (string) Str::uuid(), 'group_id' => $group, 'project_id' => $project,
        'phase' => 'incident', 'payload' => '{}', 'emitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    OutboxEvent::query()->create([
        'operation_id' => (string) Str::uuid(), 'event_type' => 'notification.intent.created',
        'contract_version' => 'notification-intent.v1', 'payload' => ['project_uuid' => $project], 'status' => 'delivered',
    ]);
    DB::table('push_operations')->insert([
        'public_id' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'project_id' => $project,
        'intent_id' => $intent, 'group_id' => $group, 'phase' => 'incident', 'severity' => 'critical',
        'thread_key' => 'incident:'.$group, 'payload' => '{}', 'status' => 'processed', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $tables = ['monitor_states', 'monitor_transitions', 'alerting_incident_groups', 'outbox_events', 'alerting_notification_intents', 'push_operations'];
    $before = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();

    AiAnnotationOperation::query()->create([
        'operation_id' => $transition->operation_id, 'transition_operation_id' => $transition->operation_id,
        'project_id' => $project, 'status' => 'queued', 'notification_side_effects' => ['before' => [], 'after' => null],
    ]);
    GenerateIncidentAnnotationJob::dispatch($transition->operation_id);
    [$successWorker, $successRequestLog, $successCallLog] = runAiAnnotationWorker($this->aiSeamDatabase);
    expect($successWorker->isSuccessful())->toBeTrue($successWorker->getErrorOutput().$successWorker->getOutput());

    [$failedTransition] = aiSeamTransition($project, $monitor);
    AiAnnotationOperation::query()->create([
        'operation_id' => $failedTransition->operation_id, 'transition_operation_id' => $failedTransition->operation_id,
        'project_id' => $project, 'status' => 'queued', 'notification_side_effects' => ['before' => [], 'after' => null],
    ]);
    GenerateIncidentAnnotationJob::dispatch($failedTransition->operation_id);
    [$failedWorker, $failedRequestLog, $failedCallLog] = runAiAnnotationWorker($this->aiSeamDatabase, 'provider_failure');
    expect($failedWorker->isSuccessful())->toBeTrue($failedWorker->getErrorOutput().$failedWorker->getOutput());

    // Exclude only the second fixture transition/outbox itself, created before its AI job.
    $before['monitor_transitions'][] = (array) DB::table('monitor_transitions')->where('operation_id', $failedTransition->operation_id)->first();
    $before['outbox_events'][] = (array) DB::table('outbox_events')->where('operation_id', $failedTransition->operation_id)->first();
    foreach ($tables as $table) {
        $after = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        expect($after)->toBe($before[$table], $table.' changed during AI processing');
    }
    foreach ([$successRequestLog, $successCallLog, $failedRequestLog, $failedCallLog] as $log) {
        @unlink($log);
    }
})->group('AC-ai-incident-annotations-8');

it('terminalizes every failure path without annotations alerts double reservation or double spend', function (string $case, string $expectedStatus, string $expectedReason): void {
    $project = (string) Str::uuid();
    $monitor = (string) Str::uuid();
    AiProjectSetting::query()->create(['project_id' => $project, 'enabled' => true]);
    [$transition] = aiSeamTransition($project, $monitor);
    AiAnnotationOperation::query()->create([
        'operation_id' => $transition->operation_id, 'transition_operation_id' => $transition->operation_id,
        'project_id' => $project, 'status' => 'queued', 'notification_side_effects' => ['before' => [], 'after' => null],
    ]);

    if ($case === 'disabled') {
        AiProjectSetting::query()->forProject($project)->update(['enabled' => false]);
    }
    GenerateIncidentAnnotationJob::dispatch($transition->operation_id);
    GenerateIncidentAnnotationJob::dispatch($transition->operation_id);
    [$worker, $requestLog, $callLog] = runAiAnnotationWorker($this->aiSeamDatabase, $case);
    expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput().$worker->getOutput());
    $calls = is_file($callLog) ? array_values(array_filter(explode("\n", trim((string) file_get_contents($callLog))))) : [];
    $operation = AiAnnotationOperation::query()->sole();
    expect($operation->status)->toBe($expectedStatus)
        ->and($operation->skip_reason)->toBe($expectedReason)
        ->and(AiIncidentAnnotation::query()->count())->toBe(0)
        ->and(AiBudgetReservation::query()->count())->toBeIn([0, 1])
        ->and($calls)->toHaveCount(in_array($case, ['unavailable', 'invalid', 'ambiguous'], true) ? 1 : 0);
    if (AiBudgetReservation::query()->exists()) {
        expect(AiBudgetReservation::query()->sole()->state)->toBe('released');
    }
    $sideEffects = $operation->notification_side_effects;
    expect($sideEffects['after']['notification_intents'])->toBe($sideEffects['before']['notification_intents'] ?? 0)
        ->and($sideEffects['after']['push_operations'])->toBe($sideEffects['before']['push_operations'] ?? 0);
    @unlink($requestLog);
    @unlink($callLog);
})->with([
    'disabled on recheck' => ['disabled', 'skipped', 'disabled'],
    'snippet denied' => ['denied', 'skipped', 'snippet_denied'],
    'snippet empty' => ['empty', 'skipped', 'empty_context'],
    'budget exhausted' => ['budget', 'failed', 'over_reservation'],
    'provider unavailable' => ['unavailable', 'failed', 'not_configured'],
    'invalid provider output' => ['invalid', 'failed', 'invalid_response'],
    'ambiguous transport acceptance' => ['ambiguous', 'failed', 'transport'],
])->group('AC-ai-incident-annotations-9');

it('crosses settings and agent HTTP through evaluator relay real worker receipt and monitor timeline HTTP', function (): void {
    $project = (string) Str::uuid();
    $server = RegisteredServer::register($project);
    $server->forceFill(['share_redacted_logs' => true])->save();
    $issued = ProjectApiToken::issue($project, 'ai-e2e-agent', ['agent:report']);

    $this->actingAs(aiSeamOperator($project))
        ->putJson('/checkybot/ai-annotations/settings', ['enabled' => true, 'version' => 0])
        ->assertOk()->assertJsonPath('data.enabled', true);

    $base = CarbonImmutable::now('UTC')->subMinutes(2);
    foreach ([0, 1, 2] as $index) {
        $this->withToken($issued->plainTextToken())
            ->postJson('/api/v2/agent-reports', aiSeamAgentPayload($server->server_uuid, $base->addSeconds($index)))
            ->assertAccepted()->assertJsonPath('data.status', 'queued');
    }

    $environment = [
        'AI_ANNOTATIONS_PROVIDER_URL' => 'https://provider.example.test/v1/annotations',
        'AI_ANNOTATIONS_PROVIDER_CREDENTIAL' => 'provider-secret',
        'AI_ANNOTATIONS_PROVIDER_MODEL' => 'bounded-model',
        'AI_ANNOTATIONS_HARNESS_FAKE' => 'true',
        'AI_ANNOTATIONS_HARNESS_ROOT_CAUSE' => 'Upstream logs show operator@example.test at 192.0.2.19 exhausted the bounded worker pool.',
        'AI_ANNOTATIONS_GLOBAL_MONTHLY_LIMIT_MICROUSD' => '1000',
        'AI_ANNOTATIONS_PROJECT_MONTHLY_LIMIT_MICROUSD' => '1000',
        'AI_ANNOTATIONS_MAX_REQUEST_MICROUSD' => '100',
        'AI_ANNOTATIONS_HARNESS_BILLED_MICROUSD' => '12',
    ];
    $evaluatorWorker = runAiSeamWorker($this->aiSeamDatabase, $environment);
    expect($evaluatorWorker->isSuccessful())->toBeTrue($evaluatorWorker->getErrorOutput().$evaluatorWorker->getOutput());
    expect(Artisan::call('checkybot:foundation-relay'))->toBe(0);
    $relayWorker = runAiSeamWorker($this->aiSeamDatabase, $environment);
    expect($relayWorker->isSuccessful())->toBeTrue($relayWorker->getErrorOutput().$relayWorker->getOutput());

    $down = MonitorTransition::query()->where('project_id', $project)->where('monitor_id', $server->server_uuid)->where('to_state', 'down')->sole();
    $receipt = $this->getJson('/__harness/ai-annotations/receipts/'.$down->operation_id)
        ->assertOk()->assertJsonPath('operation_id', $down->operation_id)
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('snippet.line_count', 3);
    $rootCause = $receipt->json('annotation.root_cause');
    expect($rootCause)->toBeString()->not->toContain('operator@example.test', '192.0.2.19', 'super-secret')
        ->and($receipt->json('notification_side_effects.before'))->toBe($receipt->json('notification_side_effects.after'));

    $timeline = $this->withHeaders(['X-Inertia' => 'true', 'Accept' => 'application/json'])
        ->get('/checkybot/monitors/server/'.$server->server_uuid)
        ->assertOk()->assertJsonPath('component', 'CheckybotDashboard/MonitorDetail');
    $rootSlot = collect($timeline->json('props.timeline.annotation_slots'))->firstWhere('key', 'root_cause');
    expect($rootSlot)->toBe(['key' => 'root_cause', 'value' => $rootCause]);
    $serialized = json_encode($timeline->json(), JSON_THROW_ON_ERROR);
    expect($serialized)->not->toContain('provider-secret', 'bounded-model', 'prompt_tokens', 'billed_microusd', 'redacted_line');
})->group('AC-ai-incident-annotations-10');
