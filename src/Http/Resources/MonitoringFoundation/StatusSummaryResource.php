<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Http\Resources\MonitoringFoundation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\StatusSummary;

/** @mixin StatusSummary */
final class StatusSummaryResource extends JsonResource
{
    /** @return array{counts: array<string, array<string, int>>, updated_at: string|null, stale: bool} */
    public function toArray(Request $request): array
    {
        return [
            'counts' => $this->counts,
            'updated_at' => $this->updatedAt?->utc()->toISOString(),
            'stale' => $this->stale,
        ];
    }
}
