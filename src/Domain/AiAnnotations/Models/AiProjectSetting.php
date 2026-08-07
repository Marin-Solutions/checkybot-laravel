<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $project_id
 * @property bool $enabled
 * @property int $version
 * @property CarbonImmutable|null $updated_at
 */
final class AiProjectSetting extends Model
{
    protected $table = 'ai_annotation_project_settings';

    protected $guarded = ['id'];

    protected $attributes = [
        'enabled' => false,
        'version' => 0,
    ];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'version' => 'integer', 'updated_at' => 'immutable_datetime'];
    }

    public function scopeForProject(Builder $query, string $projectId): Builder
    {
        return $query->where('project_id', strtolower($projectId));
    }
}
