<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MarinSolutions\CheckybotLaravel\Models\Concerns\HasPublicUuid;

/**
 * @property int $id
 * @property string $project_id
 * @property string $monitor_id
 * @property string $method
 * @property string $endpoint
 * @property int $version
 */
final class ApiMonitorConfiguration extends Model
{
    use HasPublicUuid;

    protected $table = 'api_monitor_builder_configurations';

    protected $guarded = ['id'];

    protected $hidden = ['id'];

    /** @return HasMany<ApiMonitorHeader, $this> */
    public function headers(): HasMany
    {
        return $this->hasMany(ApiMonitorHeader::class, 'configuration_id')->orderBy('position');
    }

    /** @return HasMany<ApiMonitorAssertion, $this> */
    public function assertions(): HasMany
    {
        return $this->hasMany(ApiMonitorAssertion::class, 'configuration_id')->orderBy('position');
    }

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
