<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Push\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use MarinSolutions\CheckybotLaravel\Domain\Push\Contracts\PushProjectAuthorizer;
use Throwable;

final class DefaultPushProjectAuthorizer implements PushProjectAuthorizer
{
    public function canAccess(Authenticatable $user, string $projectUuid): bool
    {
        if (method_exists($user, 'canAccessProject')) {
            return (bool) $user->canAccessProject($projectUuid);
        }

        if ((string) ($user->project_id ?? '') === $projectUuid) {
            return true;
        }

        $ids = $user->project_ids ?? null;
        if (is_array($ids) && in_array($projectUuid, array_map('strval', $ids), true)) {
            return true;
        }

        try {
            if (method_exists($user, 'can') && $user->can('accessProject', $projectUuid)) {
                return true;
            }
        } catch (Throwable) {
            // Applications may not define this optional ability.
        }

        return false;
    }
}
