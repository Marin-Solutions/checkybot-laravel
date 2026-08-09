<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery;

use MarinSolutions\CheckybotLaravel\Models\OutboxEvent;

interface FoundationEventDispatcher
{
    /** @param array<string, mixed> $sanitizedPayload
     * @return list<array<string, mixed>>
     */
    public function dispatch(OutboxEvent $event, array $sanitizedPayload): array;
}
