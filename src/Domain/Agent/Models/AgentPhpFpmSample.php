<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent\Models;

use Illuminate\Database\Eloquent\Model;

final class AgentPhpFpmSample extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'active_workers' => 'integer',
            'max_children' => 'integer',
            'max_children_reached_5m' => 'integer',
        ];
    }
}
