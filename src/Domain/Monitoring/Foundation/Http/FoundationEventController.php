<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Http;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Support\AcceptFoundationEvent;

final readonly class FoundationEventController
{
    public function __construct(private AcceptFoundationEvent $accept) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $event = $this->accept->execute($request->all());
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw ValidationException::withMessages(['operation_id' => ['The operation id has already been taken.']]);
            }

            throw $exception;
        }

        return response()->json([
            'status' => 'queued',
            'event_type' => $event->event_type,
            'operation_id' => $event->operation_id,
        ], 202);
    }
}
