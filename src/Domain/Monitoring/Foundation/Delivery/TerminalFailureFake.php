<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery;

use MarinSolutions\CheckybotLaravel\Models\OutboxEvent;

final readonly class TerminalFailureFake implements FoundationEventDispatcher
{
    public function __construct(private string $message = 'Terminal delivery failure') {}

    public function dispatch(OutboxEvent $event, array $sanitizedPayload): array
    {
        throw new TerminalDeliveryException($this->message);
    }
}
