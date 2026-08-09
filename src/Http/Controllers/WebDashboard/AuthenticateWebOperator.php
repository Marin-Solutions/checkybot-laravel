<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Http\Controllers\WebDashboard;

use Closure;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateWebOperator
{
    public const HARNESS_PROJECT_SESSION_KEY = 'checkybot.web_dashboard.project_uuid';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof Authenticatable && app()->environment(['testing', 'harness'])) {
            $projectUuid = $request->session()->get(self::HARNESS_PROJECT_SESSION_KEY);
            if (is_string($projectUuid) && Uuid::isValid($projectUuid)) {
                $operator = new GenericUser([
                    'id' => 'checkybot-web-harness-operator',
                    'current_project_id' => strtolower($projectUuid),
                    'project_id' => strtolower($projectUuid),
                    'project_ids' => [strtolower($projectUuid)],
                ]);
                Auth::setUser($operator);
                $request->setUserResolver(static fn (): Authenticatable => $operator);
            }
        }

        if (! $request->user() instanceof Authenticatable) {
            return redirect()->guest('/login');
        }

        return $next($request);
    }
}
