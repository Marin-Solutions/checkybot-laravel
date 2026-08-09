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
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Actions\EvaluateDomainExpiry;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Models\ExpandedWebsiteMonitor;

final class EvaluateDomainExpiryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 120;

    public function __construct(public readonly int $monitorId, public readonly string $observedAt) {}

    public function uniqueId(): string
    {
        return $this->monitorId.'|'.substr($this->observedAt, 0, 16);
    }

    public function handle(EvaluateDomainExpiry $evaluate): void
    {
        $monitor = ExpandedWebsiteMonitor::query()->find($this->monitorId);
        if ($monitor !== null) {
            $evaluate->execute($monitor, CarbonImmutable::parse($this->observedAt)->utc());
        }
    }
}
