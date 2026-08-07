<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use MarinSolutions\CheckybotLaravel\Models\Concerns\HasPublicUuid;

final class ProjectApiToken extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['abilities' => 'array', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function scopeForProject(Builder $query, string $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }
}
