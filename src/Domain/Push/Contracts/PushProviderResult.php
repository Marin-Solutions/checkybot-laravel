<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Push\Contracts;

final readonly class PushProviderResult
{
    private function __construct(
        public string $status,
        public ?string $ticketId = null,
        public ?string $failureCode = null,
        public ?int $retryAfter = null,
    ) {}

    public static function accepted(?string $ticketId): self
    {
        return new self('accepted', $ticketId);
    }

    public static function retry(string $code, ?int $after = null): self
    {
        return new self('retrying', failureCode: $code, retryAfter: $after);
    }

    public static function failed(string $code): self
    {
        return new self('failed', failureCode: $code);
    }

    public static function deactivated(): self
    {
        return new self('deactivated', failureCode: 'DeviceNotRegistered');
    }
}
