<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int|null $rx_delta_bytes
 * @property int|null $tx_delta_bytes
 * @property float|null $elapsed_seconds
 * @property string $sample_status
 */
final class AgentNetworkSample extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rx_bytes_total' => 'integer',
            'tx_bytes_total' => 'integer',
            'rx_delta_bytes' => 'integer',
            'tx_delta_bytes' => 'integer',
            'elapsed_seconds' => 'float',
        ];
    }
}
