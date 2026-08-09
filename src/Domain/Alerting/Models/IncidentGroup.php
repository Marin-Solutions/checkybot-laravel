<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\Severity;
use MarinSolutions\CheckybotLaravel\Models\Concerns\HasPublicUuid;

/**
 * @property string $public_id
 * @property string $project_id
 * @property Severity $severity
 * @property string $notification_thread_key
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable $latest_member_at
 * @property CarbonImmutable $collection_due_at
 * @property CarbonImmutable|null $collection_dispatched_at
 * @property CarbonImmutable|null $incident_emitted_at
 * @property CarbonImmutable|null $closed_at
 */
final class IncidentGroup extends Model
{
    use HasPublicUuid;

    protected $table = 'alerting_incident_groups';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'severity' => Severity::class,
            'opened_at' => 'immutable_datetime',
            'latest_member_at' => 'immutable_datetime',
            'collection_due_at' => 'immutable_datetime',
            'collection_dispatched_at' => 'immutable_datetime',
            'incident_emitted_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }
}
