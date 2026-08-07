<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Data;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

final readonly class ProviderRequest
{
    /** @param list<array{source:string,observed_at:string,redacted_line:string}> $lines */
    public function __construct(
        public string $operationId,
        public array $lines,
        public int $reservationMicrousd,
    ) {
        if (! Uuid::isValid($operationId) || $reservationMicrousd < 1) {
            throw new InvalidArgumentException('A valid operation and positive reservation are required.');
        }
    }
}
