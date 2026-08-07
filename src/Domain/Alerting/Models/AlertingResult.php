<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string $operation_id
 * @property string $payload_hash
 * @property string $project_id
 * @property string $monitor_id
 * @property string $monitor_type
 * @property string $source
 * @property string $signal
 * @property CarbonImmutable $observed_at
 * @property string|null $reason_code
 * @property float|null $value
 * @property array{warn: float, critical: float, recovery_delta: float}|null $thresholds
 * @property string $status
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable $created_at
 */
final class AlertingResult extends Model
{
    protected $table = 'alerting_results';

    protected $guarded = ['id'];

    protected function performUpdate(Builder $query)
    {
        $immutable = ['operation_id', 'payload_hash', 'project_id', 'monitor_id', 'monitor_type', 'source', 'signal', 'observed_at', 'reason_code', 'value', 'thresholds'];
        if ($this->isDirty($immutable)) {
            throw new LogicException('Accepted monitor result payloads are immutable.');
        }

        return parent::performUpdate($query);
    }

    protected function performDeleteOnModel()
    {
        throw new LogicException('Accepted monitor results are immutable.');
    }

    protected function casts(): array
    {
        return [
            'observed_at' => 'immutable_datetime',
            'thresholds' => 'array',
            'processed_at' => 'immutable_datetime',
            'value' => 'float',
        ];
    }
}
