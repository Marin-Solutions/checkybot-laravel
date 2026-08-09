<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Exceptions;

use RuntimeException;

final class SampleFetchException extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        string $safeMessage,
        public readonly ?int $upstreamStatus,
        public readonly int $httpStatus,
    ) {
        parent::__construct($safeMessage);
    }

    public static function timeout(): self
    {
        return new self('fetch_timeout', 'The upstream sample request timed out.', null, 504);
    }

    public static function transport(): self
    {
        return new self('fetch_failed', 'The upstream sample could not be fetched safely.', null, 502);
    }

    public static function tooLarge(?int $status = null): self
    {
        return new self('fetch_failed', 'The upstream sample exceeded the response limit.', $status, 502);
    }

    public static function nonJson(int $status): self
    {
        return new self('non_json', 'The upstream response was not valid bounded JSON.', $status, 502);
    }

    public static function upstreamAuth(int $status): self
    {
        return new self('upstream_auth', 'The upstream rejected authentication.', $status, 502);
    }
}
