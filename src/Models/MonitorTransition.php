<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\LifecycleState;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorType;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\Severity;
use MarinSolutions\CheckybotLaravel\Models\Concerns\HasPublicUuid;

final class MonitorTransition extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        $immutable = static fn (): never => throw new LogicException('Monitor transition history is immutable.');
        self::updating($immutable);
        self::deleting($immutable);
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
        ];
    }

    public function scopeForProject(Builder $query, string $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }
}
