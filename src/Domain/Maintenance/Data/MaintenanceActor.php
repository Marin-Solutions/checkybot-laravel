<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Maintenance\Data;

use Illuminate\Contracts\Auth\Authenticatable;
use MarinSolutions\CheckybotLaravel\Models\ProjectApiToken;

final readonly class MaintenanceActor
{
    private function __construct(
        public ?ProjectApiToken $token,
        public ?Authenticatable $operator,
    ) {}

    public static function token(ProjectApiToken $token): self
    {
        return new self($token, null);
    }

    public static function operator(Authenticatable $operator): self
    {
        return new self(null, $operator);
    }

    public function isProjectToken(): bool
    {
        return $this->token !== null;
    }
}
