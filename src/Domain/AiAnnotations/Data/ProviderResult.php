<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Data;

final readonly class ProviderResult
{
    private function __construct(
        public bool $successful,
        public ?ProviderFailureCode $failure,
        public ?string $probableCause,
        public int $inputTokens,
        public int $outputTokens,
        public int $billedMicrousd,
    ) {}

    public static function success(string $cause, int $inputTokens, int $outputTokens, int $billedMicrousd): self
    {
        return new self(true, null, $cause, $inputTokens, $outputTokens, $billedMicrousd);
    }

    public static function failure(ProviderFailureCode $failure): self
    {
        return new self(false, $failure, null, 0, 0, 0);
    }
}
