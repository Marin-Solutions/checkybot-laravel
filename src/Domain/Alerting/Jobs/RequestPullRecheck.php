<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\PullRecheckProducer;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\PullRetryRequest;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorIdentity;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorType;

final class RequestPullRecheck implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $requestId) {}

    public function handle(PullRecheckProducer $producer): void
    {
        $request = PullRetryRequest::query()->where('public_id', $this->requestId)->firstOrFail();
        if ($request->requested_at !== null || $request->canceled_at !== null) {
            return;
        }

        $producer->request(
            new MonitorIdentity($request->project_id, $request->monitor_id, MonitorType::from($request->monitor_type)),
            (int) $request->attempt_number,
            $request->public_id,
        );
        PullRetryRequest::query()->whereKey($request->getKey())->whereNull('requested_at')->update([
            'requested_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
