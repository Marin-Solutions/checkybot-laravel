<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent\Models;

use Illuminate\Database\Eloquent\Model;

final class AgentPrerequisite extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];
}
