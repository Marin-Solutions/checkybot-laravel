<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Push\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MarinSolutions\CheckybotLaravel\Domain\Push\Delivery\LegacyWebhookChannel;
use MarinSolutions\CheckybotLaravel\Domain\Push\Delivery\PushPayloadFactory;
use MarinSolutions\CheckybotLaravel\Domain\Push\Exceptions\RetryablePushDelivery;
use MarinSolutions\CheckybotLaravel\Domain\Push\Models\PushDeliveryAttempt;
use MarinSolutions\CheckybotLaravel\Domain\Push\Models\PushOperation;
use MarinSolutions\CheckybotLaravel\Domain\Push\Reliability\ReliabilityRecorder;
use Throwable;

final class DeliverLegacyWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public function __construct(public readonly string $attemptId) {}

    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(LegacyWebhookChannel $channel, PushPayloadFactory $payloads, ReliabilityRecorder $reliability): void
    {
        $attempt = PushDeliveryAttempt::query()->where('public_id', $this->attemptId)->firstOrFail();
        if (! in_array($attempt->status, ['queued', 'retrying'], true) || ($attempt->available_at !== null && $attempt->available_at->isFuture())) {
            return;
        }
        $attempt->forceFill(['status' => 'sending', 'provider_attempts' => $attempt->provider_attempts + 1])->save();
        $operation = PushOperation::query()->where('operation_id', $attempt->operation_id)->firstOrFail();
        $snapshot = $payloads->make($operation);
        $result = $channel->send($operation, (string) $snapshot['body']);
        $attempt->payload_snapshot = ['phase' => $operation->phase, 'severity' => 'critical', 'message' => $snapshot['body'], 'thread_key' => $operation->thread_key];

        if ($result->status === 'accepted') {
            $attempt->forceFill(['status' => 'accepted', 'failure_code' => null, 'completed_at' => now()])->save();
        } elseif ($result->status === 'retrying' && $attempt->provider_attempts < $this->tries) {
            $backoff = $result->retryAfter ?? $this->backoff()[min($attempt->provider_attempts - 1, 2)];
            $attempt->forceFill(['status' => 'retrying', 'failure_code' => $result->failureCode, 'available_at' => now()->addSeconds(max(1, $backoff))])->save();
            $reliability->refresh($operation->operation_id);
            throw new RetryablePushDelivery('Retryable legacy webhook failure.');
        } else {
            $attempt->forceFill(['status' => 'failed', 'failure_code' => $result->failureCode ?? 'provider_rejected', 'completed_at' => now()])->save();
        }
        $reliability->refresh($operation->operation_id);
    }

    public function failed(?Throwable $exception): void
    {
        $attempt = PushDeliveryAttempt::query()->where('public_id', $this->attemptId)->first();
        if ($attempt !== null && ! in_array($attempt->status, ['accepted', 'failed'], true)) {
            $attempt->forceFill(['status' => 'failed', 'failure_code' => 'retry_exhausted', 'completed_at' => now()])->save();
            app(ReliabilityRecorder::class)->refresh($attempt->operation_id);
        }
    }
}
