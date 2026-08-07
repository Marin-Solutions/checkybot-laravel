<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Push\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use MarinSolutions\CheckybotLaravel\Models\Concerns\HasPublicUuid;

/**
 * @property string $public_id
 * @property string $user_id
 * @property string $installation_id
 * @property string $project_id
 * @property string $platform
 * @property string $expo_push_token
 * @property string $expo_token_hash
 * @property string $permission
 * @property string $app_version
 * @property bool $active
 * @property CarbonImmutable $registered_at
 * @property CarbonImmutable|null $deactivated_at
 */
final class PushDevice extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected $hidden = ['expo_push_token', 'expo_token_hash'];

    protected function casts(): array
    {
        return [
            'expo_push_token' => 'encrypted',
            'active' => 'boolean',
            'registered_at' => 'immutable_datetime',
            'deactivated_at' => 'immutable_datetime',
        ];
    }
}
