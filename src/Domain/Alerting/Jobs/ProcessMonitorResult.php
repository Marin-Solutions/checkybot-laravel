<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Actions\ProcessAcceptedMonitorResult;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\AlertingResult;
use Throwable;

final class ProcessMonitorResult implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [1, 3, 10];

    public function __construct(public readonly string $operationId) {}

    public function handle(ProcessAcceptedMonitorResult $processor): void
    {
        $processor->execute($this->operationId);
    }

    public function failed(?Throwable $exception): void
    {
        AlertingResult::query()->where('operation_id', $this->operationId)->whereIn('status', ['queued', 'processing'])->update([
            'status' => 'failed',
            'failure_code' => $exception === null ? 'QueueFailure' : class_basename($exception),
            'updated_at' => now(),
        ]);
    }
}
