<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Push\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use MarinSolutions\CheckybotLaravel\Domain\Push\Models\PushDevice;

final class DeactivatePushDevice
{
    public function execute(Authenticatable $user, string $deviceUuid): bool
    {
        $device = PushDevice::query()
            ->where('public_id', $deviceUuid)
            ->where('user_id', (string) $user->getAuthIdentifier())
            ->first();
        if ($device === null) {
            return false;
        }

        if ($device->active) {
            $device->forceFill(['active' => false, 'deactivated_at' => now()])->save();
        }

        return true;
    }
}
