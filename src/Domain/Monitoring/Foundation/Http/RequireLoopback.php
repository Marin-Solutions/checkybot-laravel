<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireLoopback
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(in_array($request->ip(), ['127.0.0.1', '::1'], true), 404);

        return $next($request);
    }
}
