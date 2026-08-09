<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use MarinSolutions\CheckybotLaravel\Models\Concerns\HasPublicUuid;

/**
 * @property string $public_id
 * @property string $project_id
 * @property string $monitor_id
 * @property string $monitor_type
 * @property int $attempt_number
 * @property CarbonImmutable $due_at
 * @property CarbonImmutable|null $queued_at
 * @property CarbonImmutable|null $requested_at
 * @property CarbonImmutable|null $canceled_at
 */
final class PullRetryRequest extends Model
{
    use HasPublicUuid;

    protected $table = 'alerting_pull_retry_requests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'due_at' => 'immutable_datetime',
            'queued_at' => 'immutable_datetime',
            'requested_at' => 'immutable_datetime',
            'canceled_at' => 'immutable_datetime',
        ];
    }
}
