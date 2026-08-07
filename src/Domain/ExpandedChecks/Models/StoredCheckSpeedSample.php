<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property float|null $speed_ms
 * @property CarbonImmutable $observed_at
 */
final class StoredCheckSpeedSample extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['successful' => 'boolean', 'speed_ms' => 'float', 'observed_at' => 'immutable_datetime'];
    }
}
