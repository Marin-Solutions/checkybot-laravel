<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $mount
 * @property float $used_percent
 * @property float|null $predicted_days_to_full
 */
final class AgentDiskSample extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['used_percent' => 'float', 'predicted_days_to_full' => 'float'];
    }
}
