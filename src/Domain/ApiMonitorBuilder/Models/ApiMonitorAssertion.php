<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $configuration_id
 * @property string $kind
 * @property string $operator
 * @property string|null $json_path
 * @property string|null $expected_value
 * @property bool $has_expected
 * @property int $position
 */
final class ApiMonitorAssertion extends Model
{
    protected $table = 'api_monitor_builder_assertions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'has_expected' => 'boolean',
            'position' => 'integer',
        ];
    }
}
