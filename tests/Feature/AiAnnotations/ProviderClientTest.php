<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Actions\GenerateIncidentAnnotation;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Contracts\AiAnnotationProvider;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Data\ProviderFailureCode;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Data\ProviderRequest;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiBudgetBucket;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiBudgetReservation;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiIncidentAnnotation;

beforeEach(function (): void {
    $directory = dirname(__DIR__, 3).'/build/ai-provider-tests';
    @mkdir($directory, 0777, true);
    $this->providerDatabase = $directory.'/'.Str::uuid().'.sqlite';
    touch($this->providerDatabase);
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite', 'database' => $this->providerDatabase, 'prefix' => '',
        'foreign_key_constraints' => true, 'busy_timeout' => 10000,
    ]);
    DB::purge('sqlite');
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_900000_create_ai_annotation_tables.php')->up();
    config()->set('ai-annotations.provider', [
        'url' => 'https://ai.example.test/v1/generate', 'credential' => 'runtime-provider-secret',
        'model' => 'budget-model-v1', 'connect_timeout_seconds' => 2, 'timeout_seconds' => 6,
        'max_response_bytes' => 4096,
    ]);
    config()->set('ai-annotations.budget', [
        'global_monthly_limit_microusd' => 1000, 'project_monthly_limit_microusd' => 500,
        'max_request_microusd' => 100, 'input_token_microusd' => 1, 'output_token_microusd' => 2,
    ]);
    config()->set('ai-annotations.limits', [
        'max_lines' => 2, 'max_line_chars' => 500, 'max_input_chars' => 1200,
        'max_input_tokens' => 100, 'max_output_tokens' => 20, 'max_root_cause_chars' => 160,
    ]);
    config()->set('checkybot.monitor_foundation.redaction.secret_literals', ['configured-literal']);
});

afterEach(function (): void {
    DB::disconnect('sqlite');
    @unlink($this->providerDatabase);
});

function providerRequest(?string $operation = null, int $reservation = 100): ProviderRequest
{
    return new ProviderRequest($operation ?? (string) Str::uuid(), [[
        'source' => 'nginx',
        'observed_at' => '2026-08-08T00:00:00+00:00',
        'redacted_line' => 'GET /failed?token=query-secret Authorization: Bearer auth-secret Cookie: sid=cookie-secret configured-literal runtime-provider-secret operator@example.test 192.0.2.10 2001:db8::1',
    ]], $reservation);
}

function validProviderResponse(string $cause = 'The PHP worker pool was exhausted.', int $bill = 17): array
{
    return [
        'choices' => [['message' => ['content' => $cause]]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 3, 'billed_microusd' => $bill],
    ];
}

it('redacts recursively at egress and persists only a safe one-paragraph result', function (): void {
    $captured = null;
    Http::fake(function (Request $request) use (&$captured) {
        $captured = $request;

        return Http::response(validProviderResponse('Pool failure for admin@example.test at 203.0.113.7 and 2001:db8::5 using configured-literal.'), 200);
    });

    $operation = (string) Str::uuid();
    $transition = (string) Str::uuid();
    $project = (string) Str::uuid();
    $result = app(GenerateIncidentAnnotation::class)->execute(
        $operation,
        $transition,
        $project,
        providerRequest($operation)->lines,
    );

    expect($result->successful)->toBeTrue()->and($captured)->toBeInstanceOf(Request::class);
    $serialized = json_encode($captured->data(), JSON_THROW_ON_ERROR);
    foreach (['query-secret', 'auth-secret', 'cookie-secret', 'configured-literal', 'runtime-provider-secret', 'operator@example.test', '192.0.2.10', '2001:db8::1'] as $forbidden) {
        expect($serialized)->not->toContain($forbidden);
    }
    expect($serialized)->toContain('nginx')->toContain('2026-08-08T00:00:00+00:00')
        ->and($captured->header('Idempotency-Key')[0] ?? null)->toBe($operation)
        ->and($captured->data()['temperature'])->toBe(0)
        ->and($captured->data()['max_output_tokens'])->toBe(20)
        ->and($captured->data()['model'])->toBe('budget-model-v1');

    $headersExceptCredential = $captured->headers();
    unset($headersExceptCredential['Authorization']);
    $serializedHeaders = json_encode($headersExceptCredential, JSON_THROW_ON_ERROR);
    foreach (['query-secret', 'auth-secret', 'cookie-secret', 'configured-literal', 'runtime-provider-secret', 'operator@example.test', '192.0.2.10', '2001:db8::1'] as $forbidden) {
        expect($serializedHeaders)->not->toContain($forbidden);
    }

    $persistence = json_encode([
        AiIncidentAnnotation::query()->get()->toArray(),
        AiBudgetReservation::query()->get()->toArray(),
    ], JSON_THROW_ON_ERROR);
    foreach (['admin@example.test', '203.0.113.7', '2001:db8::5', 'configured-literal', 'query-secret', 'auth-secret', 'cookie-secret'] as $forbidden) {
        expect($persistence)->not->toContain($forbidden);
    }
    expect(AiIncidentAnnotation::query()->sole()->root_cause)->toContain('[REDACTED]')
        ->and(AiBudgetReservation::query()->sole()->state)->toBe('settled')
        ->and(AiBudgetReservation::query()->sole()->billed_microusd)->toBe(17)
        ->and(AiBudgetBucket::query()->where('scope_key', 'global')->sole()->spent_microusd)->toBe(17);

    app(GenerateIncidentAnnotation::class)->execute(
        $operation,
        $transition,
        $project,
        providerRequest($operation)->lines,
    );
    Http::assertSentCount(1);
    expect(AiIncidentAnnotation::query()->count())->toBe(1)
        ->and(AiBudgetBucket::query()->where('scope_key', 'global')->sole()->spent_microusd)->toBe(17);
})->group('AC-ai-incident-annotations-4', 'AC-ai-incident-annotations-5');

it('makes no request without HTTPS configuration or available budget', function (): void {
    Http::fake();
    config()->set('ai-annotations.provider.url', 'http://ai.example.test/generate');
    $result = app(AiAnnotationProvider::class)->annotate(providerRequest());
    expect($result->failure)->toBe(ProviderFailureCode::NotConfigured);
    Http::assertNothingSent();

    config()->set('ai-annotations.provider.url', 'https://ai.example.test/generate');
    config()->set('ai-annotations.budget.project_monthly_limit_microusd', 50);
    $operation = (string) Str::uuid();
    $result = app(GenerateIncidentAnnotation::class)->execute(
        $operation, (string) Str::uuid(), (string) Str::uuid(), providerRequest($operation)->lines,
    );
    expect($result->failure)->toBe(ProviderFailureCode::OverReservation);
    Http::assertNothingSent();
})->group('AC-ai-incident-annotations-3', 'AC-ai-incident-annotations-5');

it('returns redacted typed failures for every unsafe provider outcome', function (Closure $fake, ProviderFailureCode $expected): void {
    Http::fake($fake);
    $result = app(AiAnnotationProvider::class)->annotate(providerRequest());
    expect($result->successful)->toBeFalse()->and($result->failure)->toBe($expected)
        ->and($result->probableCause)->toBeNull();
})->with([
    'timeout' => [static fn () => throw new ConnectionException('Connection timed out'), ProviderFailureCode::Timeout],
    'transport' => [static fn () => throw new ConnectionException('Connection refused'), ProviderFailureCode::Transport],
    'rate limit' => [static fn () => Http::response([], 429), ProviderFailureCode::RateLimited],
    'redirect' => [static fn () => Http::response('', 302, ['Location' => 'https://elsewhere.example']), ProviderFailureCode::Transport],
    'malformed' => [static fn () => Http::response('{broken', 200), ProviderFailureCode::InvalidResponse],
    'multi paragraph' => [static fn () => Http::response(validProviderResponse("First.\n\nSecond."), 200), ProviderFailureCode::InvalidResponse],
    'oversized paragraph' => [static fn () => Http::response(validProviderResponse(str_repeat('x', 161)), 200), ProviderFailureCode::InvalidResponse],
    'over reservation' => [static fn () => Http::response(validProviderResponse('One paragraph.', 101), 200), ProviderFailureCode::OverReservation],
])->group('AC-ai-incident-annotations-5');
