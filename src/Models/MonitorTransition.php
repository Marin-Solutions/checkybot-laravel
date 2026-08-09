<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\LifecycleState;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorType;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\Severity;
use MarinSolutions\CheckybotLaravel\Models\Concerns\HasPublicUuid;

/**
 * @property string $public_id
 * @property string $operation_id
 * @property string $project_id
 * @property string $monitor_id
 * @property MonitorType $monitor_type
 * @property LifecycleState $from_state
 * @property LifecycleState $to_state
 * @property Severity $severity
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable|null $entered_at
 * @property string|null $reason_code
 * @property string|null $incident_group_id
 * @property bool $maintenance_suppressed
 */
final class MonitorTransition extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected function performUpdate(Builder $query)
    {
        throw new LogicException('Monitor transition history is immutable.');
    }

    protected function performDeleteOnModel()
    {
        throw new LogicException('Monitor transition history is immutable.');
    }

    protected function casts(): array
    {
        return [
            'monitor_type' => MonitorType::class,
            'from_state' => LifecycleState::class,
            'to_state' => LifecycleState::class,
            'severity' => Severity::class,
            'monitor_filter' => 'array',
            'occurred_at' => 'immutable_datetime',
            'entered_at' => 'immutable_datetime',
            'maintenance_suppressed' => 'boolean',
        ];
    }

    public function scopeForProject(Builder $query, string $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }
}
