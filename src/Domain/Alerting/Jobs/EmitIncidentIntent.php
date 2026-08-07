<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Actions\EmitDueIncidentIntent;

final class EmitIncidentIntent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [1, 3, 10, 30];

    public function __construct(public readonly string $groupId) {}

    public function handle(EmitDueIncidentIntent $emitter): void
    {
        $emitter->execute($this->groupId);
    }
}
