<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The ciphertext is hidden from every model serialization boundary. Callers that
 * need plaintext must explicitly restore an EncryptedHeaderValue and reveal it.
 *
 * @property int $id
 * @property int $configuration_id
 * @property string $name
 * @property string $normalized_name
 * @property string $encrypted_value
 * @property int $position
 */
final class ApiMonitorHeader extends Model
{
    protected $table = 'api_monitor_builder_headers';

    protected $guarded = ['id'];

    protected $hidden = ['id', 'configuration_id', 'normalized_name', 'encrypted_value', 'position', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
