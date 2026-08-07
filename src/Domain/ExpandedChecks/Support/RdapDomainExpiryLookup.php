<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Support;

use Carbon\CarbonImmutable;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Contracts\DomainExpiryLookup;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Data\DomainLookupResult;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Data\DomainName;
use Throwable;

final readonly class RdapDomainExpiryLookup implements DomainExpiryLookup
{
    public function __construct(private ClientInterface $client, private float $timeoutSeconds = 5.0) {}

    public function lookup(DomainName $domain): DomainLookupResult
    {
        $fetchedAt = CarbonImmutable::now('UTC');
        try {
            $response = $this->client->request('GET', 'https://rdap.org/domain/'.rawurlencode($domain->ascii), [
                'timeout' => $this->timeoutSeconds,
                'connect_timeout' => min(2.0, $this->timeoutSeconds),
                'headers' => ['Accept' => 'application/rdap+json, application/json'],
                'http_errors' => false,
            ]);
            $status = $response->getStatusCode();
            if ($status >= 500 || $status === 429) {
                return DomainLookupResult::failure($domain, 'domain_lookup_transport_failure', true, $fetchedAt);
            }
            if ($status >= 400) {
                return DomainLookupResult::failure($domain, 'domain_lookup_not_found', false, $fetchedAt);
            }

            /** @var mixed $payload */
            $payload = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($payload) || ! isset($payload['events']) || ! is_array($payload['events'])) {
                return DomainLookupResult::failure($domain, 'domain_lookup_malformed', true, $fetchedAt);
            }
            foreach ($payload['events'] as $event) {
                if (! is_array($event)
                    || ! in_array($event['eventAction'] ?? null, ['expiration', 'expiry', 'expires'], true)
                    || ! is_string($event['eventDate'] ?? null)) {
                    continue;
                }
                try {
                    $expiresAt = CarbonImmutable::parse($event['eventDate'])->utc();
                } catch (Throwable) {
                    return DomainLookupResult::failure($domain, 'domain_lookup_malformed', true, $fetchedAt);
                }

                return DomainLookupResult::success($domain, $expiresAt, 'rdap', $fetchedAt);
            }

            return DomainLookupResult::failure($domain, 'domain_lookup_no_expiry', true, $fetchedAt);
        } catch (ConnectException) {
            return DomainLookupResult::failure($domain, 'domain_lookup_timeout', true, $fetchedAt);
        } catch (JsonException) {
            return DomainLookupResult::failure($domain, 'domain_lookup_malformed', true, $fetchedAt);
        } catch (GuzzleException) {
            return DomainLookupResult::failure($domain, 'domain_lookup_transport_failure', true, $fetchedAt);
        }
    }
}
