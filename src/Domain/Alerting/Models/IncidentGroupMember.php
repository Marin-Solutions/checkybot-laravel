<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorType;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\Severity;

/**
 * @property string $group_id
 * @property string $project_id
 * @property string $monitor_id
 * @property MonitorType $monitor_type
 * @property Severity $severity
 * @property string $down_transition_id
 * @property CarbonImmutable $confirmed_down_at
 * @property string|null $healthy_transition_id
 * @property CarbonImmutable|null $confirmed_healthy_at
 */
final class IncidentGroupMember extends Model
{
    protected $table = 'alerting_incident_group_members';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'monitor_type' => MonitorType::class,
            'severity' => Severity::class,
            'confirmed_down_at' => 'immutable_datetime',
            'confirmed_healthy_at' => 'immutable_datetime',
        ];
    }
}
