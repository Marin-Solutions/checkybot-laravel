<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $pull_failure_streak
 * @property CarbonImmutable|null $pull_failure_started_at
 * @property string|null $push_band
 * @property int $push_streak
 * @property int $recovery_streak
 * @property CarbonImmutable|null $last_processed_observed_at
 */
final class AlertingMonitorRuntime extends Model
{
    protected $table = 'alerting_monitor_runtime';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'pull_failure_started_at' => 'immutable_datetime',
            'last_processed_observed_at' => 'immutable_datetime',
        ];
    }
}
