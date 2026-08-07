<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Contracts\RedactedLogSnippetProvider;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Data\AuthorizedLogSnippetRequest;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Data\RedactedLogSnippet;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Contracts\AiAnnotationProvider;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Data\ProviderRequest;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Data\ProviderResult;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiAnnotationOperation;

if ($argc !== 8) {
    fwrite(STDERR, "Usage: annotation-job-racer.php <root> <barrier> <ready> <result> <operation> <call-log> <worker>\n");
    exit(64);
}

[, $root, $barrier, $ready, $result, $operationId, $callLog, $worker] = $argv;
require $root.'/vendor/autoload.php';
/** @var Application $app */
$app = require $root.'/scripts/harness/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->bind(RedactedLogSnippetProvider::class, static fn (): RedactedLogSnippetProvider => new class implements RedactedLogSnippetProvider
{
    public function forIncident(AuthorizedLogSnippetRequest $request): RedactedLogSnippet
    {
        return new RedactedLogSnippet([[
            'source' => 'nginx',
            'observed_at' => $request->to->toRfc3339String(),
            'redacted_line' => 'bounded failure context',
        ]]);
    }
});
$app->bind(AiAnnotationProvider::class, static fn (): AiAnnotationProvider => new class($callLog) implements AiAnnotationProvider
{
    public function __construct(private readonly string $callLog) {}

    public function annotate(ProviderRequest $request): ProviderResult
    {
        file_put_contents($this->callLog, $request->operationId."\n", FILE_APPEND | LOCK_EX);
        usleep(300_000);

        return ProviderResult::success('Concurrent processing produced one bounded probable cause.', 8, 5, 10);
    }
});

touch($ready);
$deadline = microtime(true) + 15;
while (! is_file($barrier) && microtime(true) < $deadline) {
    usleep(20_000);
}
if (! is_file($barrier)) {
    fwrite(STDERR, "Barrier timeout.\n");
    exit(70);
}

try {
    $exit = $app->make(Kernel::class)->call('queue:work', [
        'connection' => 'database', '--once' => true, '--tries' => 1,
        '--timeout' => 15, '--no-interaction' => true,
    ]);
    $status = AiAnnotationOperation::query()->where('operation_id', $operationId)->value('status');
    file_put_contents($result, json_encode(['worker' => $worker, 'status' => $status, 'exit' => $exit], JSON_THROW_ON_ERROR));
    if ($exit !== 0) {
        exit($exit);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
