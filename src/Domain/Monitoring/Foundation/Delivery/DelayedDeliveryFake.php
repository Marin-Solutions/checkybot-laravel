<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery;

use MarinSolutions\CheckybotLaravel\Models\OutboxEvent;

final readonly class DelayedDeliveryFake implements FoundationEventDispatcher
{
    public function __construct(private int $seconds = 30) {}

    public function dispatch(OutboxEvent $event, array $sanitizedPayload): array
    {
        throw new RetryableDeliveryException('Consumer requested a deterministic delay.', max(1, $this->seconds));
    }
}
