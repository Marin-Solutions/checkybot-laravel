<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent\Http;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MarinSolutions\CheckybotLaravel\Models\ProjectApiToken;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateAgentReportToken
{
    public const REQUEST_ATTRIBUTE = 'checkybot_agent_project_api_token';

    public function handle(Request $request, Closure $next): Response
    {
        $plainText = $request->bearerToken();
        $token = is_string($plainText) && $plainText !== '' ? ProjectApiToken::authenticate($plainText) : null;

        if ($token === null) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }
        if (! $token->allows('agent:report')) {
            return new JsonResponse(['message' => 'The token cannot report for this server.'], 403);
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $token);

        return $next($request);
    }
}
