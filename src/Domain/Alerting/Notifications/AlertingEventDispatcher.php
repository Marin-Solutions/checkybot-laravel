<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Notifications;

use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery\DeterministicFakeEventDispatcher;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery\FoundationEventDispatcher;
use MarinSolutions\CheckybotLaravel\Models\OutboxEvent;

final readonly class AlertingEventDispatcher implements FoundationEventDispatcher
{
    public function __construct(private DeterministicFakeEventDispatcher $foundation) {}

    public function dispatch(OutboxEvent $event, array $sanitizedPayload): array
    {
        if ($event->event_type !== 'notification.intent.created') {
            return $this->foundation->dispatch($event, $sanitizedPayload);
        }

        $duplicate = collect($event->receipts ?? [])->contains(
            static fn (array $receipt): bool => ($receipt['consumer'] ?? null) === 'alerting-notification-intents',
        );

        return [[
            'consumer' => 'alerting-notification-intents',
            'contract_version' => $event->contract_version,
            'effect' => $duplicate ? 'notification-intent-duplicate' : 'notification-intent-recorded',
            'consumer_ack' => $duplicate ? 'duplicate' : 'processed',
            'operation_id' => $event->operation_id,
            'delivered_at' => now()->toISOString(),
        ]];
    }
}
