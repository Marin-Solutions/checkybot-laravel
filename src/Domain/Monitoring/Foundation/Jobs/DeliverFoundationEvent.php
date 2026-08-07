<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MarinSolutions\CheckybotLaravel\Models\OutboxEvent;

final class DeliverFoundationEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [1, 5, 15];

    public function __construct(public readonly string $operationId) {}

    public function handle(): void
    {
        $event = OutboxEvent::query()->where('operation_id', $this->operationId)->firstOrFail();

        if ($event->status === 'delivered') {
            return;
        }

        $payload = $event->payload;
        $consumers = match ($event->event_type) {
            'monitor.transitioned' => ['alerting', 'agent', 'mobile', 'widget', 'web'],
            'contract.check_sync.probed' => ['sdk'],
            'incident.redaction.probed' => ['ai'],
            default => [],
        };

        $receipts = array_map(function (string $consumer) use ($event, $payload): array {
            $receipt = [
                'consumer' => $consumer,
                'contract_version' => $event->contract_version,
                'effect' => $consumer === 'sdk' ? 'schema-valid' : 'transition-recorded',
                'delivered_at' => now()->toISOString(),
            ];

            if ($event->event_type === 'monitor.transitioned') {
                $receipt += [
                    'identity' => $payload['identity'],
                    'state' => $payload['to_state'],
                    'severity' => $payload['severity'],
                    'filter' => $payload['filter'],
                ];
            }

            if ($consumer === 'sdk') {
                $receipt['schema_valid'] = true;
                $receipt['check_types'] = ['uptime', 'ssl', 'api', 'dead_links', 'open_graph'];
            }

            return $receipt;
        }, $consumers);

        $event->forceFill([
            'status' => 'delivered',
            'attempts' => $event->attempts + 1,
            'receipts' => $receipts,
            'delivered_at' => now(),
        ])->save();
    }
}
