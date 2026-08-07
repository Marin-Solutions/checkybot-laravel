<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Contracts\RedactedLogSnippetProvider;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Data\AuthorizedLogSnippetRequest;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Data\RedactedLogSnippet;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Contracts\AiAnnotationProvider;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Data\ProviderFailureCode;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Data\ProviderRequest;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Data\ProviderResult;
use MarinSolutions\CheckybotLaravel\Tests\TestCase;

if ($argc !== 6) {
    fwrite(STDERR, "Usage: ai-annotation-worker.php <root> <sqlite-database> <scenario> <request-log> <call-log>\n");
    exit(64);
}

[, $root, $database, $scenario, $requestLog, $callLog] = $argv;
$realRoot = realpath($root);
$realDatabase = realpath($database);
if ($realRoot === false || $realDatabase === false || ! str_starts_with($realDatabase, $realRoot.DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "Refusing a queue database outside the workspace.\n");
    exit(65);
}

require $realRoot.'/vendor/autoload.php';
$testCase = new class('test_placeholder') extends TestCase
{
    public function test_placeholder(): void {}

    public function bootApplication(): Application
    {
        parent::setUp();

        return $this->app;
    }
};
$app = $testCase->bootApplication();
$app['config']->set('database.default', 'sqlite');
$app['config']->set('database.connections.sqlite', [
    'driver' => 'sqlite', 'database' => $realDatabase, 'prefix' => '',
    'foreign_key_constraints' => true, 'busy_timeout' => 15000,
]);
$app['config']->set('queue.default', 'database');
$app['config']->set('queue.connections.database', [
    'driver' => 'database', 'connection' => 'sqlite', 'table' => 'jobs', 'queue' => 'default',
    'retry_after' => 90, 'after_commit' => true,
]);
$app['config']->set('ai-annotations.provider.url', 'https://provider.example.test/v1/annotations');
$app['config']->set('ai-annotations.provider.credential', 'provider-secret');
$app['config']->set('ai-annotations.provider.model', 'bounded-model');
$app['config']->set('ai-annotations.budget.global_monthly_limit_microusd', 1000);
$app['config']->set('ai-annotations.budget.project_monthly_limit_microusd', $scenario === 'budget' ? 0 : 1000);
$app['config']->set('ai-annotations.budget.max_request_microusd', 100);
DB::purge('sqlite');

$app->bind(RedactedLogSnippetProvider::class, static fn (): RedactedLogSnippetProvider => new class($scenario, $requestLog) implements RedactedLogSnippetProvider
{
    public function __construct(private readonly string $scenario, private readonly string $requestLog) {}

    public function forIncident(AuthorizedLogSnippetRequest $request): RedactedLogSnippet
    {
        file_put_contents($this->requestLog, json_encode([
            'authorized_project_id' => $request->authorizedProjectId,
            'project_id' => $request->identity->projectId,
            'monitor_id' => $request->identity->monitorId,
            'type' => $request->identity->type->value,
            'from' => $request->from->toRfc3339String(),
            'to' => $request->to->toRfc3339String(),
            'sources' => $request->sources,
            'limit' => $request->limit,
        ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);

        if ($this->scenario === 'denied') {
            return new RedactedLogSnippet(denied: true, denialReason: 'disabled');
        }
        if ($this->scenario === 'empty') {
            return new RedactedLogSnippet([]);
        }

        return new RedactedLogSnippet([[
            'source' => 'nginx',
            'observed_at' => $request->to->toRfc3339String(),
            'redacted_line' => 'upstream timeout [REDACTED]',
        ]], truncated: true, alertEligible: $this->scenario === 'alert_eligible', redactionVersion: 'foundation-recursive.v1');
    }
});
$app->bind(AiAnnotationProvider::class, static fn (): AiAnnotationProvider => new class($scenario, $callLog) implements AiAnnotationProvider
{
    public function __construct(private readonly string $scenario, private readonly string $callLog) {}

    public function annotate(ProviderRequest $request): ProviderResult
    {
        file_put_contents($this->callLog, $request->operationId."\n", FILE_APPEND | LOCK_EX);
        $failure = match ($this->scenario) {
            'unavailable' => ProviderFailureCode::NotConfigured,
            'invalid' => ProviderFailureCode::InvalidResponse,
            'ambiguous' => ProviderFailureCode::Transport,
            'provider_failure' => ProviderFailureCode::Transport,
            default => null,
        };

        return $failure === null
            ? ProviderResult::success('Upstream saturation for [REDACTED] at [REDACTED] caused the outage.', 5, 3, 10)
            : ProviderResult::failure($failure);
    }
});

exit($app->make(Kernel::class)->call('queue:work', [
    'connection' => 'database', '--stop-when-empty' => true, '--sleep' => 1,
    '--tries' => 1, '--timeout' => 15, '--no-interaction' => true,
]));
