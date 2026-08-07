<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use MarinSolutions\CheckybotLaravel\Models\Concerns\HasPublicUuid;

/**
 * @property string $public_id
 * @property string $operation_id
 * @property string $group_id
 * @property string $project_id
 * @property string $phase
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $emitted_at
 */
final class NotificationIntent extends Model
{
    use HasPublicUuid;

    protected $table = 'alerting_notification_intents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'emitted_at' => 'immutable_datetime',
        ];
    }
}
