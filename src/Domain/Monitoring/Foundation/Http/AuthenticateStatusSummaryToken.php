<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Http;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MarinSolutions\CheckybotLaravel\Domain\Security\Foundation\ProjectTokenAbility;
use MarinSolutions\CheckybotLaravel\Models\ProjectApiToken;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateStatusSummaryToken
{
    public const REQUEST_ATTRIBUTE = 'checkybot_project_api_token';

    public function handle(Request $request, Closure $next): Response
    {
        $plainText = $request->bearerToken();
        $token = is_string($plainText) && $plainText !== ''
            ? ProjectApiToken::authenticate($plainText)
            : null;

        if ($token === null) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        if (! $token->allows(ProjectTokenAbility::StatusRead)) {
            return new JsonResponse(['message' => 'This token cannot read status summaries.'], 403);
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $token);

        return $next($request);
    }
}
