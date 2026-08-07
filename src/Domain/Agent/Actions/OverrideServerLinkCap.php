<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent\Actions;

use Illuminate\Validation\ValidationException;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\RegisteredServer;

final readonly class OverrideServerLinkCap
{
    public function execute(string $projectId, string $serverUuid, int $linkCapBps): RegisteredServer
    {
        if ($linkCapBps <= 0) {
            throw ValidationException::withMessages(['link_cap_bps' => ['The link cap must be a positive integer.']]);
        }

        $server = RegisteredServer::query()
            ->where('project_id', $projectId)
            ->where('server_uuid', $serverUuid)
            ->first();
        if ($server === null) {
            throw ValidationException::withMessages(['server_uuid' => ['The server is not registered in this project.']]);
        }

        $server->forceFill(['link_cap_bps' => $linkCapBps])->save();

        return $server->refresh();
    }
}
