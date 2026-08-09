<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Models;

use Illuminate\Database\Eloquent\Model;

final class ExpandedCheckEvaluation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value' => 'float', 'details' => 'array', 'observed_at' => 'immutable_datetime'];
    }
}
