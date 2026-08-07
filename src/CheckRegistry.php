<?php

namespace MarinSolutions\CheckybotLaravel;

use JsonSerializable;
use MarinSolutions\CheckybotLaravel\Checks\ApiCheck;
use MarinSolutions\CheckybotLaravel\Checks\DomainExpiryCheck;
use MarinSolutions\CheckybotLaravel\Checks\LinkCheck;
use MarinSolutions\CheckybotLaravel\Checks\OpenGraphCheck;
use MarinSolutions\CheckybotLaravel\Checks\ResponseTimeBudgetCheck;
use MarinSolutions\CheckybotLaravel\Checks\SslCheck;
use MarinSolutions\CheckybotLaravel\Checks\UptimeCheck;

/** Registry for fluent monitor definitions. */
class CheckRegistry implements JsonSerializable
{
    public function __construct(
        private readonly CheckSyncPayloadSerializer $serializer = new CheckSyncPayloadSerializer,
    ) {}

    /** @var list<UptimeCheck> */
    protected array $uptimeChecks = [];

    /** @var list<SslCheck> */
    protected array $sslChecks = [];

    /** @var list<ApiCheck> */
    protected array $apiChecks = [];

    /** @var list<LinkCheck> */
    protected array $linkChecks = [];

    /** @var list<OpenGraphCheck> */
    protected array $openGraphChecks = [];

    /** @var list<DomainExpiryCheck> */
    protected array $domainExpiryChecks = [];

    /** @var list<ResponseTimeBudgetCheck> */
    protected array $responseTimeBudgetChecks = [];

    public function uptime(string $name): UptimeCheck
    {
        return $this->uptimeChecks[] = new UptimeCheck($name);
    }

    public function ssl(string $name): SslCheck
    {
        return $this->sslChecks[] = new SslCheck($name);
    }

    public function api(string $name): ApiCheck
    {
        return $this->apiChecks[] = new ApiCheck($name);
    }

    public function links(string $name): LinkCheck
    {
        return $this->linkChecks[] = new LinkCheck($name);
    }

    public function openGraph(string $name): OpenGraphCheck
    {
        return $this->openGraphChecks[] = new OpenGraphCheck($name);
    }

    public function domainExpiry(string $name): DomainExpiryCheck
    {
        return $this->domainExpiryChecks[] = new DomainExpiryCheck($name);
    }

    public function responseTimeBudget(string $name): ResponseTimeBudgetCheck
    {
        return $this->responseTimeBudgetChecks[] = new ResponseTimeBudgetCheck($name);
    }

    /** @return list<UptimeCheck> */
    public function getUptimeChecks(): array
    {
        return $this->uptimeChecks;
    }

    /** @return list<SslCheck> */
    public function getSslChecks(): array
    {
        return $this->sslChecks;
    }

    /** @return list<ApiCheck> */
    public function getApiChecks(): array
    {
        return $this->apiChecks;
    }

    /** @return list<LinkCheck> */
    public function getLinkChecks(): array
    {
        return $this->linkChecks;
    }

    /** @return list<OpenGraphCheck> */
    public function getOpenGraphChecks(): array
    {
        return $this->openGraphChecks;
    }

    /** @return list<DomainExpiryCheck> */
    public function getDomainExpiryChecks(): array
    {
        return $this->domainExpiryChecks;
    }

    /** @return list<ResponseTimeBudgetCheck> */
    public function getResponseTimeBudgetChecks(): array
    {
        return $this->responseTimeBudgetChecks;
    }

    public function count(): int
    {
        return count($this->uptimeChecks)
            + count($this->sslChecks)
            + count($this->apiChecks)
            + count($this->linkChecks)
            + count($this->openGraphChecks)
            + count($this->domainExpiryChecks)
            + count($this->responseTimeBudgetChecks);
    }

    /** @return $this */
    public function flush(): self
    {
        $this->uptimeChecks = [];
        $this->sslChecks = [];
        $this->apiChecks = [];
        $this->linkChecks = [];
        $this->openGraphChecks = [];
        $this->domainExpiryChecks = [];
        $this->responseTimeBudgetChecks = [];

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->serializer->fromRegistry($this);
    }

    /** @return array<string, mixed> */
    public function toSafeArray(): array
    {
        return $this->maskHeaders($this->toArray());
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toSafeArray();
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->toSafeArray();
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        return $this->toSafeArray();
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        $this->serializer = new CheckSyncPayloadSerializer;
        $this->uptimeChecks = [];
        $this->sslChecks = [];
        $this->apiChecks = [];
        $this->linkChecks = [];
        $this->openGraphChecks = [];
        $this->domainExpiryChecks = [];
        $this->responseTimeBudgetChecks = [];
    }

    /** @param array<string, mixed> $value */
    private function maskHeaders(array $value): array
    {
        foreach ($value as $key => $child) {
            if ($key === 'headers' && is_array($child)) {
                $value[$key] = array_fill_keys(array_keys($child), '[REDACTED]');
            } elseif (is_array($child)) {
                $value[$key] = $this->maskHeaders($child);
            }
        }

        return $value;
    }
}
