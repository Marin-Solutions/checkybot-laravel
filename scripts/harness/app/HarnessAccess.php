<?php

declare(strict_types=1);

namespace Checkybot\Harness;

use Illuminate\Http\Request;

final class HarnessAccess
{
    public static function routesAreEnabled(): bool
    {
        return getenv('APP_ENV') === 'harness'
            && is_string(getenv('HARNESS_RUN_ID'))
            && getenv('HARNESS_RUN_ID') !== ''
            && is_string(getenv('HARNESS_RUN_DIR'))
            && getenv('HARNESS_RUN_DIR') !== '';
    }

    public static function requestIsLoopback(Request $request): bool
    {
        return in_array($request->ip(), ['127.0.0.1', '::1'], true);
    }

    public static function workerIsReady(): bool
    {
        $runDirectory = (string) getenv('HARNESS_RUN_DIR');
        $pidFile = $runDirectory.'/worker.pid';
        $readyFile = $runDirectory.'/worker.ready';

        if (! is_file($pidFile) || ! is_file($readyFile)) {
            return false;
        }

        $pid = (int) trim((string) file_get_contents($pidFile));

        return $pid > 1 && self::processBelongsToRun($pid);
    }

    private static function processBelongsToRun(int $pid): bool
    {
        $environmentFile = "/proc/{$pid}/environ";

        if (! is_readable($environmentFile)) {
            return false;
        }

        $environment = (string) file_get_contents($environmentFile);
        $runId = (string) getenv('HARNESS_RUN_ID');
        $runDirectory = (string) getenv('HARNESS_RUN_DIR');

        return str_contains($environment, "HARNESS_RUN_ID={$runId}\0")
            && str_contains($environment, "HARNESS_RUN_DIR={$runDirectory}\0");
    }
}
