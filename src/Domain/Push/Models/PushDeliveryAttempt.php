<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Push\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use MarinSolutions\CheckybotLaravel\Models\Concerns\HasPublicUuid;

/**
 * @property string $public_id
 * @property string $attempt_key
 * @property string $operation_id
 * @property string|null $device_id
 * @property string $channel
 * @property string $status
 * @property int $provider_attempts
 * @property string|null $ticket_id
 * @property string|null $failure_code
 * @property array<string, mixed>|null $payload_snapshot
 * @property CarbonImmutable|null $available_at
 * @property CarbonImmutable|null $completed_at
 */
final class PushDeliveryAttempt extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload_snapshot' => 'array',
            'available_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
