<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorIdentity;
use Ramsey\Uuid\Uuid;

final readonly class AuthorizedLogSnippetRequest
{
    /** @param list<string> $sources */
    public function __construct(
        public MonitorIdentity $identity,
        public string $authorizedProjectId,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public array $sources,
        public int $limit,
    ) {
        if (! Uuid::isValid($authorizedProjectId)) {
            throw new InvalidArgumentException('The authorization project must be a UUID.');
        }
    }
}
