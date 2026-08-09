<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\IncidentGroup;

final readonly class EmitDueIncidentIntent
{
    public function __construct(private WriteNotificationIntent $intents) {}

    public function execute(string $groupId): void
    {
        DB::transaction(function () use ($groupId): void {
            $group = IncidentGroup::query()->where('public_id', $groupId)->lockForUpdate()->first();
            if ($group === null
                || $group->closed_at !== null
                || $group->incident_emitted_at !== null
                || $group->collection_due_at->isAfter(CarbonImmutable::instance(now()))) {
                return;
            }

            $this->intents->incident($group);
            $group->incident_emitted_at = CarbonImmutable::instance(now());
            $group->save();
        }, 5);
    }
}
