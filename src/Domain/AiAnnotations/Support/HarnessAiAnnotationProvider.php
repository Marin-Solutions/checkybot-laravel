<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Support;

use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Contracts\AiAnnotationProvider;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Data\ProviderRequest;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Data\ProviderResult;
use MarinSolutions\CheckybotLaravel\Domain\Security\Foundation\RecursiveRedactor;

/** Deterministic provider available only in explicit testing/harness runtime. */
final readonly class HarnessAiAnnotationProvider implements AiAnnotationProvider
{
    public function __construct(private RecursiveRedactor $redactor) {}

    public function annotate(ProviderRequest $request): ProviderResult
    {
        $cause = trim((string) $this->redactor->redact((string) config(
            'ai-annotations.provider.harness_root_cause',
            'The bounded server logs indicate upstream saturation caused the confirmed outage.',
        )));
        $cause = trim((string) (new RecursiveRedactor([
            (string) config('ai-annotations.provider.credential', ''),
        ]))->redact($cause));
        $billed = min($request->reservationMicrousd, max(0, (int) config('ai-annotations.provider.harness_billed_microusd', 10)));

        return ProviderResult::success($cause, 20, 12, $billed);
    }
}
