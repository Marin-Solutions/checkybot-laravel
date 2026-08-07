<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Data;

final readonly class AiSettingsData
{
    /** @param array{period:string,monthly_limit_microusd:int,reserved_microusd:int,spent_microusd:int,remaining_microusd:int} $budget */
    public function __construct(
        public bool $enabled,
        public int $version,
        public bool $providerConfigured,
        public array $budget,
        public ?string $updatedAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'version' => $this->version,
            'provider_configured' => $this->providerConfigured,
            'budget' => $this->budget,
            'updated_at' => $this->updatedAt,
        ];
    }
}
