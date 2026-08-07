<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Data\DomainName;
use Ramsey\Uuid\Uuid;

/**
 * @property string $project_id
 * @property string $monitor_id
 * @property string $check_type
 * @property string|null $canonical_domain
 * @property bool $enabled
 * @property int $retained_sample_limit
 * @property int $sample_max_age_seconds
 */
final class ExpandedWebsiteMonitor extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'retained_sample_limit' => 'integer', 'sample_max_age_seconds' => 'integer'];
    }

    public static function domainExpiry(string $projectId, string $monitorId, string $domain, bool $enabled = true): self
    {
        self::assertIds($projectId, $monitorId);

        return self::query()->create([
            'project_id' => $projectId,
            'monitor_id' => $monitorId,
            'check_type' => 'domain_expiry',
            'canonical_domain' => (new DomainName($domain))->ascii,
            'enabled' => $enabled,
        ]);
    }

    public static function responseBudget(string $projectId, string $monitorId, bool $enabled = true, int $limit = 100, int $maxAgeSeconds = 900): self
    {
        self::assertIds($projectId, $monitorId);
        if ($limit < 1 || $limit > 1000 || $maxAgeSeconds < 60) {
            throw new \InvalidArgumentException('Response budget retention settings are out of range.');
        }

        return self::query()->create([
            'project_id' => $projectId,
            'monitor_id' => $monitorId,
            'check_type' => 'response_budget',
            'enabled' => $enabled,
            'retained_sample_limit' => $limit,
            'sample_max_age_seconds' => $maxAgeSeconds,
        ]);
    }

    /** @return HasOne<DomainExpiryObservation, $this> */
    public function domainObservation(): HasOne
    {
        return $this->hasOne(DomainExpiryObservation::class);
    }

    private static function assertIds(string $projectId, string $monitorId): void
    {
        if (! Uuid::isValid($projectId) || ! Uuid::isValid($monitorId)) {
            throw new \InvalidArgumentException('Expanded checks require UUID project and monitor identities.');
        }
    }
}
