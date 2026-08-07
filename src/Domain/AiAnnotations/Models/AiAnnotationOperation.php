<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $operation_id
 * @property string $transition_operation_id
 * @property string $project_id
 * @property string $status
 * @property string|null $skip_reason
 * @property int $snippet_line_count
 * @property bool $snippet_truncated
 * @property string|null $redaction_version
 * @property array<string, mixed>|null $notification_side_effects
 */
final class AiAnnotationOperation extends Model
{
    protected $table = 'ai_annotation_operations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'snippet_line_count' => 'integer',
            'snippet_truncated' => 'boolean',
            'notification_side_effects' => 'array',
        ];
    }

    public function scopeForProject(Builder $query, string $projectId): Builder
    {
        return $query->where('project_id', strtolower($projectId));
    }
}
