<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property string $server_uuid
 * @property string $project_id
 * @property bool $enabled
 * @property int $link_cap_bps
 * @property bool $share_redacted_logs
 */
final class RegisteredServer extends Model
{
    public const DEFAULT_LINK_CAP_BPS = 1_000_000_000;

    protected $table = 'agent_servers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'link_cap_bps' => 'integer',
            'share_redacted_logs' => 'boolean',
        ];
    }

    public static function register(string $projectId, ?string $serverUuid = null): self
    {
        return self::query()->create([
            'server_uuid' => $serverUuid ?? (string) Str::uuid(),
            'project_id' => $projectId,
        ])->refresh();
    }
}
