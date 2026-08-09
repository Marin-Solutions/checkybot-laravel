<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Security\Foundation;

use JsonSerializable;
use MarinSolutions\CheckybotLaravel\Models\ProjectApiToken;
use Stringable;

final readonly class IssuedProjectApiToken implements JsonSerializable, Stringable
{
    public function __construct(
        public ProjectApiToken $accessToken,
        private string $plainTextToken,
    ) {}

    public function plainTextToken(): string
    {
        return $this->plainTextToken;
    }

    public function jsonSerialize(): array
    {
        return ['token' => '[REDACTED]', 'access_token' => $this->accessToken];
    }

    public function __toString(): string
    {
        return '[REDACTED]';
    }

    public function __debugInfo(): array
    {
        return ['token' => '[REDACTED]', 'accessToken' => $this->accessToken];
    }
}
