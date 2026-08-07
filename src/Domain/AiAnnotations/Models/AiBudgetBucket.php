<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $scope_key
 * @property string|null $project_id
 * @property string $period
 * @property int $reserved_microusd
 * @property int $spent_microusd
 */
final class AiBudgetBucket extends Model
{
    protected $table = 'ai_annotation_budget_buckets';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reserved_microusd' => 'integer', 'spent_microusd' => 'integer'];
    }

    public function scopeForProject(Builder $query, string $projectId): Builder
    {
        return $query->where('scope_key', 'project:'.strtolower($projectId))->where('project_id', strtolower($projectId));
    }
}
