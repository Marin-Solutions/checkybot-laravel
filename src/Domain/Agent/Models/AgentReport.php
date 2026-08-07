<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $operation_id
 * @property string $payload_hash
 * @property string $project_id
 * @property array<string, mixed> $payload
 * @property-read RegisteredServer $server
 */
final class AgentReport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'observed_at' => 'immutable_datetime',
            'evaluation_prepared_at' => 'immutable_datetime',
            'evaluation_link_cap_bps' => 'integer',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(RegisteredServer::class, 'agent_server_id');
    }

    public function disks(): HasMany
    {
        return $this->hasMany(AgentDiskSample::class);
    }

    public function networkInterfaces(): HasMany
    {
        return $this->hasMany(AgentNetworkSample::class);
    }

    public function phpFpmPools(): HasMany
    {
        return $this->hasMany(AgentPhpFpmSample::class);
    }

    public function prerequisites(): HasMany
    {
        return $this->hasMany(AgentPrerequisite::class);
    }

    public function relevantLogLines(): HasMany
    {
        return $this->hasMany(AgentRedactedLogLine::class);
    }
}
