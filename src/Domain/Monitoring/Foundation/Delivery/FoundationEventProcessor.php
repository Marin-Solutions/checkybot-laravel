<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery;

use MarinSolutions\CheckybotLaravel\Domain\Security\Foundation\RecursiveRedactor;
use MarinSolutions\CheckybotLaravel\Models\OutboxEvent;
use Throwable;

final readonly class FoundationEventProcessor
{
    public function __construct(
        private FoundationEventDispatcher $dispatcher,
        private RecursiveRedactor $redactor,
    ) {}

    public function process(string $operationId): void
    {
        $claimed = OutboxEvent::query()
            ->where('operation_id', $operationId)
            ->where('status', 'pending')
            ->where(static fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->update([
                'status' => 'processing',
                'claim_token' => null,
                'claimed_at' => null,
                'updated_at' => now(),
            ]);

        // A duplicate or early queue message is a no-op. Only a due event can own the
        // pending -> processing CAS, so queued duplicates cannot bypass retry backoff.
        if ($claimed !== 1) {
            return;
        }

        $event = OutboxEvent::query()->where('operation_id', $operationId)->firstOrFail();
        $sanitizedPayload = $this->redactor->redact($event->payload);

        try {
            $receipts = $this->dispatcher->dispatch($event, $sanitizedPayload);

            $event->forceFill([
                'status' => 'delivered',
                'attempts' => $event->attempts + 1,
                'receipts' => $receipts,
                'sanitized_payload' => $event->event_type === 'incident.redaction.probed' ? $sanitizedPayload : null,
                'failure_metadata' => null,
                'delivered_at' => now(),
            ])->save();
        } catch (RetryableDeliveryException $exception) {
            $this->recordRetry($event, $exception);
        } catch (Throwable $exception) {
            $this->recordTerminalFailure($event, $exception);
        }
    }

    private function recordRetry(OutboxEvent $event, RetryableDeliveryException $exception): void
    {
        $attempts = $event->attempts + 1;
        $maxAttempts = max(1, (int) config('checkybot.monitor_foundation.delivery.max_attempts', 3));

        if ($attempts >= $maxAttempts) {
            $this->recordTerminalFailure($event, $exception, $attempts);

            return;
        }

        $configured = config('checkybot.monitor_foundation.delivery.backoff_seconds', [5, 30, 120]);
        $backoff = is_array($configured) ? $configured : [5, 30, 120];
        $delay = $exception->retryAfterSeconds
            ?? (int) ($backoff[min($attempts - 1, max(0, count($backoff) - 1))] ?? 5);

        $event->forceFill([
            'status' => 'pending',
            'attempts' => $attempts,
            'available_at' => now()->addSeconds(max(1, $delay)),
            'failure_metadata' => $this->failureMetadata($exception, false),
        ])->save();
    }

    private function recordTerminalFailure(OutboxEvent $event, Throwable $exception, ?int $attempts = null): void
    {
        $event->forceFill([
            'status' => 'failed',
            'attempts' => $attempts ?? $event->attempts + 1,
            'available_at' => null,
            'failure_metadata' => $this->failureMetadata($exception, true),
        ])->save();
    }

    /** @return array<string, mixed> */
    private function failureMetadata(Throwable $exception, bool $terminal): array
    {
        return $this->redactor->redact([
            'exception' => class_basename($exception),
            'message' => $exception->getMessage(),
            'terminal' => $terminal,
            'recorded_at' => now()->toISOString(),
        ]);
    }
}
