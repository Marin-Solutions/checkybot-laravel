<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Push\Http;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MarinSolutions\CheckybotLaravel\Models\ProjectApiToken;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticatePushUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        if (is_string($bearer) && $bearer !== '' && ProjectApiToken::authenticate($bearer) !== null) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        if (! $request->user() instanceof Authenticatable) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
