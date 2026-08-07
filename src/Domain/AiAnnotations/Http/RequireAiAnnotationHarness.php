<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireAiAnnotationHarness
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app()->environment(['testing', 'harness']), 404);

        return $next($request);
    }
}
