<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\LifecycleState;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorType;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\Severity;
use MarinSolutions\CheckybotLaravel\Models\Concerns\HasPublicUuid;

/**
 * @property string $project_id
 * @property string $monitor_id
 * @property MonitorType $monitor_type
 * @property LifecycleState $state
 * @property Severity $severity
 * @property CarbonImmutable $observed_at
 * @property CarbonImmutable|null $entered_at
 */
final class MonitorState extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'monitor_type' => MonitorType::class,
            'state' => LifecycleState::class,
            'severity' => Severity::class,
            'observed_at' => 'immutable_datetime',
            'entered_at' => 'immutable_datetime',
        ];
    }

    public function scopeForProject(Builder $query, string $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }
}
