<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Maintenance\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use MarinSolutions\CheckybotLaravel\Models\Concerns\HasPublicUuid;

/**
 * @property string $public_id
 * @property string $operation_id
 * @property string $payload_hash
 * @property string $scope
 * @property string|null $project_id
 * @property string|null $reason
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable|null $cleared_at
 * @property CarbonImmutable|null $catch_up_queued_at
 * @property CarbonImmutable|null $catch_up_claimed_at
 */
final class MaintenanceMode extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'cleared_at' => 'immutable_datetime',
            'catch_up_queued_at' => 'immutable_datetime',
            'catch_up_claimed_at' => 'immutable_datetime',
        ];
    }

    public function isActiveAt(CarbonImmutable $at): bool
    {
        return $this->cleared_at === null
            && $this->starts_at->lessThanOrEqualTo($at)
            && $this->ends_at->greaterThan($at);
    }
}
