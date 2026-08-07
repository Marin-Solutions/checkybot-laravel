<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string $operation_id
 * @property string $transition_operation_id
 * @property string $project_id
 * @property string $root_cause
 */
final class AiIncidentAnnotation extends Model
{
    protected $table = 'ai_incident_annotations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['generated_at' => 'immutable_datetime'];
    }

    public function scopeForProject(Builder $query, string $projectId): Builder
    {
        return $query->where('project_id', strtolower($projectId));
    }

    protected function performUpdate(Builder $query)
    {
        throw new LogicException('Completed AI annotations are immutable.');
    }

    protected function performDeleteOnModel()
    {
        throw new LogicException('Completed AI annotations are immutable.');
    }
}
