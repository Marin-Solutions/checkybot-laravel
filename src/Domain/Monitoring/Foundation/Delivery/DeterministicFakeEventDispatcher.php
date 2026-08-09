<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery;

use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Queries\StatusSummaryQuery;
use MarinSolutions\CheckybotLaravel\Models\OutboxEvent;

final readonly class DeterministicFakeEventDispatcher implements FoundationEventDispatcher
{
    public function __construct(private StatusSummaryQuery $statusSummary) {}

    public function dispatch(OutboxEvent $event, array $sanitizedPayload): array
    {
        $consumers = match ($event->event_type) {
            'monitor.transitioned' => ['alerting', 'agent', 'mobile', 'widget', 'web'],
            'contract.check_sync.probed' => ['sdk'],
            'incident.redaction.probed' => ['ai'],
            default => throw new TerminalDeliveryException('No consumer is registered for this event type.'),
        };

        return array_map(function (string $consumer) use ($event, $sanitizedPayload): array {
            $receipt = [
                'consumer' => $consumer,
                'contract_version' => $event->contract_version,
                'effect' => match ($consumer) {
                    'sdk' => 'schema-valid',
                    'ai' => 'incident-sanitized',
                    default => 'transition-recorded',
                },
                'delivered_at' => now()->toISOString(),
            ];

            if ($event->event_type === 'monitor.transitioned') {
                $summary = $this->statusSummary->forProject($sanitizedPayload['identity']['project_id']);
                $receipt += [
                    'identity' => $sanitizedPayload['identity'],
                    'state' => $sanitizedPayload['to_state'],
                    'severity' => $sanitizedPayload['severity'],
                    'filter' => $sanitizedPayload['filter'],
                    'status_summary' => [
                        'counts' => $summary->counts,
                        'updated_at' => $summary->updatedAt?->utc()->toISOString(),
                        'stale' => $summary->stale,
                    ],
                ];
            }

            if ($consumer === 'sdk') {
                $receipt['schema_valid'] = true;
                $receipt['check_types'] = array_values(array_filter(
                    ['uptime', 'ssl', 'api', 'dead_links', 'open_graph', 'domain_expiry', 'response_time_budget'],
                    static fn (string $type): bool => array_key_exists($type, $sanitizedPayload),
                ));
            }

            return $receipt;
        }, $consumers);
    }
}
