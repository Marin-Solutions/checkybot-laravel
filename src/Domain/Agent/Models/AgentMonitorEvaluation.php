<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $operation_id
 * @property string $project_id
 * @property string $signal
 * @property int $band_value
 * @property string $reason_code
 * @property CarbonImmutable $observed_at
 * @property-read RegisteredServer $server
 */
final class AgentMonitorEvaluation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'band_value' => 'integer',
            'details' => 'array',
            'observed_at' => 'immutable_datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(RegisteredServer::class, 'agent_server_id');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(AgentReport::class, 'agent_report_id');
    }
}
