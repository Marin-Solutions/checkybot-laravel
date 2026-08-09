<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery;

use MarinSolutions\CheckybotLaravel\Models\OutboxEvent;

final readonly class RetryableFailureFake implements FoundationEventDispatcher
{
    public function __construct(private string $message = 'Configured secret failed at admin@example.test from 2001:db8::1') {}

    public function dispatch(OutboxEvent $event, array $sanitizedPayload): array
    {
        throw new RetryableDeliveryException($this->message);
    }
}
