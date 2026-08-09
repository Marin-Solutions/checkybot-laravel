<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $agent_server_id
 * @property string $project_id
 * @property CarbonImmutable $last_accepted_observed_at
 * @property int $reporting_interval_seconds
 * @property-read RegisteredServer $server
 */
final class AgentServerLiveness extends Model
{
    protected $table = 'agent_server_liveness';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_accepted_observed_at' => 'immutable_datetime',
            'reporting_interval_seconds' => 'integer',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(RegisteredServer::class, 'agent_server_id');
    }
}
