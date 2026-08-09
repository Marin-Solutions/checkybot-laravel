<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent\Data;

use JsonSerializable;

final readonly class RedactedLogSnippet implements JsonSerializable
{
    /**
     * @param  list<array{source: string, observed_at: string, redacted_line: string}>  $lines
     */
    public function __construct(
        public array $lines = [],
        public bool $truncated = false,
        public bool $alertEligible = false,
        public string $redactionVersion = 'foundation-recursive.v1',
        public bool $denied = false,
        public ?string $denialReason = null,
    ) {}

    /**
     * @return array{
     *   lines: list<array{source: string, observed_at: string, redacted_line: string}>,
     *   truncated: bool,
     *   alert_eligible: false,
     *   redaction_version: string,
     *   denied: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'lines' => $this->lines,
            'truncated' => $this->truncated,
            'alert_eligible' => false,
            'redaction_version' => $this->redactionVersion,
            'denied' => $this->denied,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
