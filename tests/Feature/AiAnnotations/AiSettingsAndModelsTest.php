<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Actions\BudgetLedger;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiAnnotationOperation;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiBudgetBucket;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiIncidentAnnotation;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiProjectSetting;
use Symfony\Component\Process\Process;

function aiOperator(string $current, array $projects): GenericUser
{
    return new GenericUser(['id' => 'ai-operator', 'current_project_id' => $current, 'project_ids' => $projects]);
}

beforeEach(function (): void {
    $directory = dirname(__DIR__, 3).'/build/ai-annotation-tests';
    @mkdir($directory, 0777, true);
    $this->aiDatabase = $directory.'/'.Str::uuid().'.sqlite';
    touch($this->aiDatabase);
    config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite', 'database' => $this->aiDatabase, 'prefix' => '',
        'foreign_key_constraints' => true, 'busy_timeout' => 15000,
    ]);
    DB::purge('sqlite');
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_900000_create_ai_annotation_tables.php')->up();
    config()->set('ai-annotations.provider', [
        'url' => 'https://provider.example.test/v1/annotations', 'credential' => 'provider-secret',
        'model' => 'budget-model', 'connect_timeout_seconds' => 2, 'timeout_seconds' => 5,
        'max_response_bytes' => 65536,
    ]);
    config()->set('ai-annotations.budget', [
        'global_monthly_limit_microusd' => 1000, 'project_monthly_limit_microusd' => 500,
        'max_request_microusd' => 100, 'input_token_microusd' => 1, 'output_token_microusd' => 2,
    ]);
});

afterEach(function (): void {
    DB::disconnect('sqlite');
    @unlink($this->aiDatabase);
});

it('enforces defaults uniqueness immutability and project-scoped reads and writes', function (): void {
    $project = strtolower((string) Str::uuid());
    $foreign = strtolower((string) Str::uuid());
    $transition = (string) Str::uuid();
    $operation = (string) Str::uuid();

    $setting = AiProjectSetting::query()->create(['project_id' => $project]);
    expect($setting->enabled)->toBeFalse()->and($setting->version)->toBe(0);
    expect(fn () => AiProjectSetting::query()->create(['project_id' => $project]))->toThrow(QueryException::class);

    AiProjectSetting::query()->create(['project_id' => $foreign, 'enabled' => true]);
    AiProjectSetting::query()->forProject($project)->update(['enabled' => true]);
    expect(AiProjectSetting::query()->forProject($project)->sole()->enabled)->toBeTrue()
        ->and(AiProjectSetting::query()->forProject($foreign)->sole()->enabled)->toBeTrue()
        ->and(AiProjectSetting::query()->forProject((string) Str::uuid())->exists())->toBeFalse();

    AiAnnotationOperation::query()->create([
        'operation_id' => $operation, 'transition_operation_id' => $transition,
        'project_id' => $project, 'status' => 'completed',
    ]);
    expect(fn () => AiAnnotationOperation::query()->create([
        'operation_id' => (string) Str::uuid(), 'transition_operation_id' => $transition,
        'project_id' => $foreign,
    ]))->toThrow(QueryException::class);

    $annotation = AiIncidentAnnotation::query()->create([
        'operation_id' => $operation, 'transition_operation_id' => $transition,
        'project_id' => $project, 'root_cause' => 'A bounded cause.', 'generated_at' => now(),
    ]);
    expect(fn () => AiIncidentAnnotation::query()->create([
        'operation_id' => (string) Str::uuid(), 'transition_operation_id' => $transition,
        'project_id' => $foreign, 'root_cause' => 'Foreign.', 'generated_at' => now(),
    ]))->toThrow(QueryException::class)
        ->and(fn () => $annotation->update(['root_cause' => 'changed']))->toThrow(LogicException::class)
        ->and(AiAnnotationOperation::query()->forProject($foreign)->count())->toBe(0)
        ->and(AiIncidentAnnotation::query()->forProject($foreign)->count())->toBe(0);

    AiBudgetBucket::query()->create(['scope_key' => 'project:'.$project, 'project_id' => $project, 'period' => '2026-08']);
    expect(fn () => AiBudgetBucket::query()->create(['scope_key' => 'project:'.$project, 'project_id' => $project, 'period' => '2026-08']))
        ->toThrow(QueryException::class)
        ->and(AiBudgetBucket::query()->forProject($foreign)->count())->toBe(0);
})->group('AC-ai-incident-annotations-1');

it('serves optimistic current-project settings and validates opt-in configuration', function (): void {
    $project = strtolower((string) Str::uuid());
    $foreign = strtolower((string) Str::uuid());

    $this->getJson('/checkybot/ai-annotations/settings')->assertRedirect('/login');
    $this->putJson('/checkybot/ai-annotations/settings', ['enabled' => true, 'version' => 0])->assertRedirect('/login');

    $this->actingAs(aiOperator($project, [$foreign]))->getJson('/checkybot/ai-annotations/settings')
        ->assertForbidden()->assertJsonPath('message', 'The operator cannot manage AI annotations for the selected project.');

    $this->actingAs(aiOperator($project, [$project, $foreign]));
    $this->getJson('/checkybot/ai-annotations/settings')
        ->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.version', 0)
        ->assertJsonPath('data.budget.period', CarbonImmutable::now('UTC')->format('Y-m'));
    $this->getJson('/checkybot/ai-annotations/settings?project_id='.$foreign)->assertUnprocessable();
    $this->putJson('/checkybot/ai-annotations/settings', ['enabled' => true, 'version' => 0, 'project_id' => $foreign])
        ->assertUnprocessable();

    $this->putJson('/checkybot/ai-annotations/settings', ['enabled' => true, 'version' => 0])
        ->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.version', 1);
    expect(AiProjectSetting::query()->forProject($project)->sole()->enabled)->toBeTrue()
        ->and(AiProjectSetting::query()->forProject($foreign)->exists())->toBeFalse();
    $this->putJson('/checkybot/ai-annotations/settings', ['enabled' => false, 'version' => 0])
        ->assertStatus(409)->assertJsonPath('message', 'AI annotation settings changed; reload before saving.');
    $this->putJson('/checkybot/ai-annotations/settings', ['enabled' => false, 'version' => 1])
        ->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.version', 2);

    // Laravel bypasses CSRF only while its environment is "testing". Exercise
    // the real web middleware in production mode to prove a missing token is rejected.
    $environment = app('env');
    app()->instance('env', 'production');
    try {
        $this->putJson('/checkybot/ai-annotations/settings', ['enabled' => false, 'version' => 2])
            ->assertStatus(419);
    } finally {
        app()->instance('env', $environment);
    }

    config()->set('ai-annotations.provider.url', 'http://provider.example.test');
    $this->putJson('/checkybot/ai-annotations/settings', ['enabled' => true, 'version' => 2])
        ->assertUnprocessable()->assertJsonValidationErrors(['enabled', 'provider_configuration']);
    config()->set('ai-annotations.provider.url', 'https://provider.example.test');
    config()->set('ai-annotations.budget.project_monthly_limit_microusd', 0);
    $this->putJson('/checkybot/ai-annotations/settings', ['enabled' => true, 'version' => 2])
        ->assertUnprocessable()->assertJsonValidationErrors('enabled');

    $route = Route::getRoutes()->getByName('checkybot.ai-annotations.settings.update');
    expect($route)->not->toBeNull()->and($route->gatherMiddleware())->toContain('web');
})->group('AC-ai-incident-annotations-2');

it('keeps simultaneous reservations within both caps and starts a fresh UTC month', function (): void {
    config()->set('ai-annotations.budget.global_monthly_limit_microusd', 100);
    config()->set('ai-annotations.budget.project_monthly_limit_microusd', 100);
    config()->set('ai-annotations.budget.max_request_microusd', 100);
    $project = (string) Str::uuid();
    $root = dirname(__DIR__, 3);
    $directory = $root.'/build/ai-annotation-tests';
    $runId = (string) Str::uuid();
    $runDirectory = $directory.'/'.$runId;
    foreach (['bootstrap/cache', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $path) {
        @mkdir($runDirectory.'/'.$path, 0777, true);
    }
    $barrier = $directory.'/barrier-'.Str::uuid();
    $processes = [];
    $files = [];
    $environment = [
        'APP_ENV' => 'testing', 'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->aiDatabase,
        'AI_ANNOTATIONS_GLOBAL_MONTHLY_LIMIT_MICROUSD' => '100',
        'AI_ANNOTATIONS_PROJECT_MONTHLY_LIMIT_MICROUSD' => '100',
        'AI_ANNOTATIONS_MAX_REQUEST_MICROUSD' => '100',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
        'HARNESS_RUN_ID' => $runId, 'HARNESS_RUN_DIR' => $runDirectory,
    ];
    foreach ([0, 1] as $index) {
        $ready = $barrier.'-ready-'.$index;
        $result = $barrier.'-result-'.$index;
        $files[] = $ready;
        $files[] = $result;
        $process = new Process([
            PHP_BINARY, __DIR__.'/Support/budget-racer.php', $root, $barrier, $ready, $result,
            (string) Str::uuid(), $project, '2026-08-31T23:59:59Z',
        ], $root, $environment);
        $process->setTimeout(30);
        $process->start();
        $processes[] = $process;
    }
    $deadline = microtime(true) + 10;
    while (count(array_filter([$barrier.'-ready-0', $barrier.'-ready-1'], 'is_file')) !== 2 && microtime(true) < $deadline) {
        usleep(20_000);
    }
    expect(is_file($barrier.'-ready-0'))->toBeTrue()->and(is_file($barrier.'-ready-1'))->toBeTrue();
    touch($barrier);
    foreach ($processes as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
    }
    $results = array_map(static fn (int $index): array => json_decode((string) file_get_contents($barrier.'-result-'.$index), true, 8, JSON_THROW_ON_ERROR), [0, 1]);
    expect(collect($results)->where('acquired', true))->toHaveCount(1);
    foreach ([$barrier, ...$files] as $file) {
        @unlink($file);
    }

    $augustProject = AiBudgetBucket::query()->forProject($project)->where('period', '2026-08')->sole();
    $augustGlobal = AiBudgetBucket::query()->where('scope_key', 'global')->where('period', '2026-08')->sole();
    expect($augustProject->reserved_microusd + $augustProject->spent_microusd)->toBeLessThanOrEqual(100)
        ->and($augustGlobal->reserved_microusd + $augustGlobal->spent_microusd)->toBeLessThanOrEqual(100);

    $ledger = app(BudgetLedger::class);
    $septemberOperation = (string) Str::uuid();
    $september = $ledger->reserve($septemberOperation, $project, CarbonImmutable::parse('2026-09-01T00:00:00Z'));
    expect($september->acquired)->toBeTrue();
    expect($ledger->settle($septemberOperation, 41))->toBeTrue()
        ->and($ledger->settle($septemberOperation, 41))->toBeFalse();
    $septemberBucket = AiBudgetBucket::query()->forProject($project)->where('period', '2026-09')->sole();
    expect($septemberBucket->reserved_microusd)->toBe(0)->and($septemberBucket->spent_microusd)->toBe(41);
})->group('AC-ai-incident-annotations-3');
