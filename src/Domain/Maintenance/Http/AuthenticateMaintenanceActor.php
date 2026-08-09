<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Maintenance\Http;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Data\MaintenanceActor;
use MarinSolutions\CheckybotLaravel\Models\ProjectApiToken;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateMaintenanceActor
{
    public const REQUEST_ATTRIBUTE = 'checkybot_maintenance_actor';

    public function handle(Request $request, Closure $next): Response
    {
        $plainText = $request->bearerToken();
        if (is_string($plainText) && $plainText !== '') {
            $token = ProjectApiToken::authenticate($plainText);
            if ($token === null) {
                return new JsonResponse(['message' => 'Unauthenticated.'], 401);
            }
            $request->attributes->set(self::REQUEST_ATTRIBUTE, MaintenanceActor::token($token));

            return $next($request);
        }

        $operator = $request->user();
        if (! $operator instanceof Authenticatable) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }
        $request->attributes->set(self::REQUEST_ATTRIBUTE, MaintenanceActor::operator($operator));

        return $next($request);
    }
}
