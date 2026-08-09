<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Jobs\DeliverFoundationEvent;
use MarinSolutions\CheckybotLaravel\Models\OutboxEvent;
use Throwable;

final class RelayFoundationOutbox extends Command
{
    protected $signature = 'checkybot:foundation-relay {--limit=100}';

    protected $description = 'Relay pending monitor foundation outbox events to the queue';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $leaseCutoff = now()->subSeconds(max(1, (int) config('checkybot.monitor_foundation.relay_claim_seconds', 60)));
        $operationIds = OutboxEvent::query()
            ->where('status', 'pending')
            ->where(static fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->where(static fn ($query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<=', $leaseCutoff))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('operation_id');
        $relayed = 0;

        foreach ($operationIds as $operationId) {
            $claimToken = (string) Str::uuid();
            $claimed = DB::transaction(function () use ($operationId, $claimToken, $leaseCutoff): bool {
                return OutboxEvent::query()
                    ->where('operation_id', $operationId)
                    ->where('status', 'pending')
                    ->where(static fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
                    ->where(static fn ($query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<=', $leaseCutoff))
                    ->update([
                        'claim_token' => $claimToken,
                        'claimed_at' => now(),
                        'updated_at' => now(),
                    ]) === 1;
            });

            if (! $claimed) {
                continue;
            }

            try {
                DeliverFoundationEvent::dispatch((string) $operationId);
                $relayed++;
            } catch (Throwable $exception) {
                OutboxEvent::query()
                    ->where('operation_id', $operationId)
                    ->where('claim_token', $claimToken)
                    ->update(['claim_token' => null, 'claimed_at' => null, 'updated_at' => now()]);
                report($exception);
            }
        }

        $this->info("Relayed {$relayed} foundation event(s).");

        return self::SUCCESS;
    }
}
