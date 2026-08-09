<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Push\Reliability;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use MarinSolutions\CheckybotLaravel\Domain\Push\Models\PushDeliveryAttempt;
use MarinSolutions\CheckybotLaravel\Domain\Push\Models\PushOperation;

final class ReliabilityRecorder
{
    public function refresh(string $operationId): void
    {
        $operation = PushOperation::query()->where('operation_id', $operationId)->first();
        if ($operation === null || $operation->severity !== 'critical' || ! (bool) config('checkybot.push.proving_enabled', true)) {
            return;
        }

        DB::transaction(function () use ($operation): void {
            $this->recordChannel($operation, 'expo');
            $this->recordChannel($operation, 'legacy_webhook');
            $this->rebuildDay($operation->project_id, CarbonImmutable::instance($operation->created_at)->utc()->toDateString());
        }, 3);
    }

    private function recordChannel(PushOperation $operation, string $channel): void
    {
        $attempts = PushDeliveryAttempt::query()
            ->where('operation_id', $operation->operation_id)
            ->where('channel', $channel)
            ->get();
        if ($attempts->isEmpty() || $attempts->contains(fn ($attempt): bool => in_array($attempt->status, ['queued', 'sending', 'retrying'], true))) {
            return;
        }
        $accepted = $attempts->every(fn ($attempt): bool => $attempt->status === 'accepted');
        $key = hash('sha256', "{$operation->operation_id}|{$channel}");
        DB::table('push_reliability_receipts')->updateOrInsert(
            ['receipt_key' => $key],
            [
                'operation_id' => $operation->operation_id,
                'project_id' => $operation->project_id,
                'channel' => $channel,
                'status' => $accepted ? 'accepted' : 'failed',
                'proving_date' => CarbonImmutable::instance($operation->created_at)->utc()->toDateString(),
                'recorded_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function rebuildDay(string $projectId, string $date): void
    {
        $operations = PushOperation::query()
            ->where('project_id', $projectId)
            ->where('severity', 'critical')
            ->whereDate('created_at', $date)
            ->pluck('operation_id');
        $critical = $operations->count();
        $receipts = DB::table('push_reliability_receipts')->whereIn('operation_id', $operations)->get();
        $expo = $receipts->where('channel', 'expo')->where('status', 'accepted')->unique('operation_id')->count();
        $legacy = $receipts->where('channel', 'legacy_webhook')->where('status', 'accepted')->unique('operation_id')->count();
        $complete = $operations->filter(function (string $id) use ($receipts): bool {
            return $receipts->contains(fn ($receipt): bool => $receipt->operation_id === $id && $receipt->channel === 'expo' && $receipt->status === 'accepted')
                && $receipts->contains(fn ($receipt): bool => $receipt->operation_id === $id && $receipt->channel === 'legacy_webhook' && $receipt->status === 'accepted');
        })->count();
        $failed = max(0, $critical - $complete);

        DB::table('push_proving_days')->updateOrInsert(
            ['project_id' => $projectId, 'proving_date' => $date],
            [
                'critical_intents' => $critical,
                'expo_accepted' => $expo,
                'legacy_webhook_accepted' => $legacy,
                'failed_or_missing_pairs' => $failed,
                'last_failure_at' => $failed > 0 ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}
