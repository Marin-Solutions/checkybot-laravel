<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts;

final readonly class WatchdogPingResult
{
    private function __construct(
        public string $status,
        public ?string $reason = null,
        public ?int $httpStatus = null,
    ) {}

    public static function disabled(): self
    {
        return new self('disabled');
    }

    public static function succeeded(int $httpStatus): self
    {
        return new self('success', httpStatus: $httpStatus);
    }

    public static function failed(string $reason, ?int $httpStatus = null): self
    {
        return new self('failure', $reason, $httpStatus);
    }
}
