<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Actions\RefreshDomainExpiry;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Models\ExpandedWebsiteMonitor;

final class RefreshDomainExpiryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 86400;

    public function __construct(public readonly int $monitorId, public readonly string $observedAt) {}

    public function uniqueId(): string
    {
        return $this->monitorId.'|'.substr($this->observedAt, 0, 10);
    }

    public function handle(RefreshDomainExpiry $refresh): void
    {
        $monitor = ExpandedWebsiteMonitor::query()->find($this->monitorId);
        if ($monitor !== null) {
            $refresh->execute($monitor, CarbonImmutable::parse($this->observedAt)->utc());
        }
    }
}
