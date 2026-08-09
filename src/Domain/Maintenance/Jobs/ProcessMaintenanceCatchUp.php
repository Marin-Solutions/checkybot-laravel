<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Maintenance\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Actions\RunMaintenanceCatchUp;
use RuntimeException;

final class ProcessMaintenanceCatchUp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $maintenanceModeId,
        public readonly ?string $barrierPath = null,
        public readonly ?string $readyPath = null,
    ) {}

    public function handle(RunMaintenanceCatchUp $catchUp): void
    {
        $this->awaitConcurrencyBarrier();
        $catchUp->execute($this->maintenanceModeId);
    }

    private function awaitConcurrencyBarrier(): void
    {
        if ($this->barrierPath === null && $this->readyPath === null) {
            return;
        }
        if ($this->barrierPath === null || $this->readyPath === null) {
            throw new RuntimeException('Both maintenance catch-up barrier paths are required.');
        }

        $workspace = realpath(dirname(__DIR__, 4));
        $barrierDirectory = realpath(dirname($this->barrierPath));
        $readyDirectory = realpath(dirname($this->readyPath));
        if ($workspace === false || $barrierDirectory === false || $readyDirectory === false
            || ! str_starts_with($barrierDirectory, $workspace.DIRECTORY_SEPARATOR)
            || ! str_starts_with($readyDirectory, $workspace.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Maintenance catch-up barriers must stay inside the workspace.');
        }

        touch($this->readyPath);
        $deadline = microtime(true) + 30;
        while (microtime(true) < $deadline) {
            clearstatcache(true, $this->barrierPath);
            if (is_file($this->barrierPath)) {
                break;
            }
            usleep(10_000);
        }
        clearstatcache(true, $this->barrierPath);
        if (! is_file($this->barrierPath)) {
            throw new RuntimeException('Maintenance catch-up concurrency barrier timed out.');
        }
    }
}
