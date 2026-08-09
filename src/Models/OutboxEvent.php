<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Models;

use Illuminate\Database\Eloquent\Model;
use MarinSolutions\CheckybotLaravel\Models\Concerns\HasPublicUuid;

/**
 * @property string $public_id
 * @property string $operation_id
 * @property string $event_type
 * @property string $contract_version
 * @property string $status
 * @property int $attempts
 * @property array<string, mixed> $payload
 * @property array<int, array<string, mixed>>|null $receipts
 * @property array<string, mixed>|null $sanitized_payload
 * @property array<string, mixed>|null $failure_metadata
 */
final class OutboxEvent extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'receipts' => 'array',
            'sanitized_payload' => 'array',
            'available_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'failure_metadata' => 'array',
        ];
    }
}
