<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Push\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use MarinSolutions\CheckybotLaravel\Models\Concerns\HasPublicUuid;

/**
 * @property string $public_id
 * @property string $operation_id
 * @property string $project_id
 * @property string $intent_id
 * @property string $group_id
 * @property string $phase
 * @property string $severity
 * @property string $thread_key
 * @property array<string, mixed> $payload
 * @property string $status
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable $created_at
 */
final class PushOperation extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'immutable_datetime'];
    }
}
