<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

/**
 * @property int $agent_server_id
 * @property string $operation_id
 * @property string $payload_hash
 * @property string $project_id
 * @property float $cpu_five_min_percent
 * @property float $memory_used_percent
 * @property int $nginx_window_seconds
 * @property int $nginx_total_requests
 * @property int $nginx_five_xx_count
 * @property int $nginx_upstream_timeout_count
 * @property CarbonImmutable $observed_at
 * @property array<string, mixed> $payload
 * @property-read RegisteredServer $server
 * @property-read Collection<int, AgentDiskSample> $disks
 * @property-read Collection<int, AgentNetworkSample> $networkInterfaces
 * @property-read Collection<int, AgentPhpFpmSample> $phpFpmPools
 * @property-read Collection<int, AgentPrerequisite> $prerequisites
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

    public function monitorEvaluation(): HasOne
    {
        return $this->hasOne(AgentMonitorEvaluation::class);
    }
}
