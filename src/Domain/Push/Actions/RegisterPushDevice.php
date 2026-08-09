<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Push\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use MarinSolutions\CheckybotLaravel\Domain\Push\Data\RegisteredPushDevice;
use MarinSolutions\CheckybotLaravel\Domain\Push\Data\RegisterPushDeviceData;
use MarinSolutions\CheckybotLaravel\Domain\Push\Models\PushDevice;

final class RegisterPushDevice
{
    public function execute(Authenticatable $user, RegisterPushDeviceData $data): RegisteredPushDevice
    {
        try {
            return $this->persist($user, $data);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            return $this->persist($user, $data);
        }
    }

    private function persist(Authenticatable $user, RegisterPushDeviceData $data): RegisteredPushDevice
    {
        return DB::transaction(function () use ($user, $data): RegisteredPushDevice {
            $userId = (string) $user->getAuthIdentifier();
            $device = PushDevice::query()
                ->where('user_id', $userId)
                ->where('installation_id', $data->installationId)
                ->lockForUpdate()
                ->first();
            $created = $device === null;
            $device ??= new PushDevice([
                'user_id' => $userId,
                'installation_id' => $data->installationId,
            ]);
            $tokenHash = hash('sha256', $data->expoPushToken);

            PushDevice::query()
                ->where('expo_token_hash', $tokenHash)
                ->when($device->exists, fn ($query) => $query->whereKeyNot($device->getKey()))
                ->where('active', true)
                ->update(['active' => false, 'deactivated_at' => now(), 'updated_at' => now()]);

            $device->forceFill([
                'project_id' => $data->projectUuid,
                'platform' => $data->platform,
                'expo_push_token' => $data->expoPushToken,
                'expo_token_hash' => $tokenHash,
                'permission' => $data->permission,
                'app_version' => $data->appVersion,
                'active' => true,
                'registered_at' => now(),
                'deactivated_at' => null,
            ])->save();

            return new RegisteredPushDevice($device->fresh(), $created);
        }, 3);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }
}
