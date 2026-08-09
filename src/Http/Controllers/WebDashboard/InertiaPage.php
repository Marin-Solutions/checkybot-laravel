<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Http\Controllers\WebDashboard;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class InertiaPage
{
    /** @param array<string, mixed> $props */
    public function render(Request $request, string $component, array $props): SymfonyResponse
    {
        $page = [
            'component' => $component,
            'props' => $props,
            'url' => $request->getRequestUri(),
            'version' => null,
        ];

        if ($request->header('X-Inertia') === 'true' || $request->expectsJson()) {
            return new JsonResponse($page, 200, $this->headers());
        }

        $encoded = json_encode($page, JSON_THROW_ON_ERROR | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG);
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>'
            .'<body><div id="app" data-page="'.htmlspecialchars($encoded, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"></div></body></html>';

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Vary' => 'X-Inertia']);
    }

    /** @param array<string, list<string>> $errors */
    public function validation(array $errors): JsonResponse
    {
        return new JsonResponse([
            'message' => 'The given data was invalid.',
            'errors' => $errors,
        ], 422, $this->headers());
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Inertia' => 'true', 'Vary' => 'X-Inertia'];
    }
}
