<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent\Models;

use Illuminate\Database\Eloquent\Model;

final class AgentRedactedLogLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['observed_at' => 'immutable_datetime'];
    }
}
