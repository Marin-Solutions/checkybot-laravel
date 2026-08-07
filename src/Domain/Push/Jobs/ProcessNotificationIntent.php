<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Push\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use MarinSolutions\CheckybotLaravel\Domain\Push\Models\PushDeliveryAttempt;
use MarinSolutions\CheckybotLaravel\Domain\Push\Models\PushDevice;
use MarinSolutions\CheckybotLaravel\Domain\Push\Models\PushOperation;
use MarinSolutions\CheckybotLaravel\Domain\Push\Reliability\ReliabilityRecorder;

final class ProcessNotificationIntent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $operationId) {}

    public function handle(ReliabilityRecorder $reliability): void
    {
        $attemptIds = DB::transaction(function (): array {
            $claimed = PushOperation::query()
                ->where('operation_id', $this->operationId)
                ->where('status', 'queued')
                ->update(['status' => 'processing', 'updated_at' => now()]);
            if ($claimed !== 1) {
                return [];
            }
            $operation = PushOperation::query()->where('operation_id', $this->operationId)->firstOrFail();
            $ids = [];
            foreach (PushDevice::query()->where('project_id', $operation->project_id)->where('active', true)->orderBy('id')->get() as $device) {
                $attempt = $this->attempt($operation->operation_id, $device->public_id, 'expo');
                $ids[] = [$attempt->public_id, 'expo'];
            }
            if ($operation->severity === 'critical' && (bool) config('checkybot.push.proving_enabled', true)) {
                $attempt = $this->attempt($operation->operation_id, null, 'legacy_webhook');
                $ids[] = [$attempt->public_id, 'legacy_webhook'];
            }
            $operation->forceFill(['status' => 'processed', 'processed_at' => now()])->save();

            return $ids;
        }, 3);

        if ($attemptIds === []) {
            return;
        }
        $reliability->refresh($this->operationId);
        foreach ($attemptIds as [$attemptId, $channel]) {
            if ($channel === 'expo') {
                DeliverExpoPush::dispatch($attemptId)->afterCommit();
            } else {
                DeliverLegacyWebhook::dispatch($attemptId)->afterCommit();
            }
        }
    }

    private function attempt(string $operationId, ?string $deviceId, string $channel): PushDeliveryAttempt
    {
        $key = hash('sha256', implode('|', [$operationId, $deviceId ?? 'intent', $channel]));

        return PushDeliveryAttempt::query()->firstOrCreate(
            ['attempt_key' => $key],
            ['operation_id' => $operationId, 'device_id' => $deviceId, 'channel' => $channel, 'available_at' => now()],
        );
    }
}
