<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $operation_id
 * @property string $project_id
 * @property string $period
 * @property int $reserved_microusd
 * @property int|null $billed_microusd
 * @property string $state
 */
final class AiBudgetReservation extends Model
{
    protected $table = 'ai_annotation_budget_reservations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reserved_microusd' => 'integer', 'billed_microusd' => 'integer', 'settled_at' => 'immutable_datetime'];
    }

    public function scopeForProject(Builder $query, string $projectId): Builder
    {
        return $query->where('project_id', strtolower($projectId));
    }
}
