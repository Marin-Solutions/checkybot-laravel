<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Data;

use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiBudgetReservation;

final readonly class BudgetReservationResult
{
    public function __construct(
        public bool $allowed,
        public bool $acquired,
        public ?AiBudgetReservation $reservation = null,
    ) {}
}
