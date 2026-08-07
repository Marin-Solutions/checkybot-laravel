<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Jobs\DeliverFoundationEvent;
use MarinSolutions\CheckybotLaravel\Models\OutboxEvent;

final class RelayFoundationOutbox extends Command
{
    protected $signature = 'checkybot:foundation-relay {--limit=100}';

    protected $description = 'Relay pending monitor foundation outbox events to the queue';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $operationIds = OutboxEvent::query()
            ->where('status', 'pending')
            ->where(static fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('operation_id');

        foreach ($operationIds as $operationId) {
            $claimed = DB::transaction(function () use ($operationId): bool {
                return OutboxEvent::query()
                    ->where('operation_id', $operationId)
                    ->where('status', 'pending')
                    ->update(['status' => 'queued', 'updated_at' => now()]) === 1;
            });

            if ($claimed) {
                DeliverFoundationEvent::dispatch((string) $operationId);
            }
        }

        $this->info("Relayed {$operationIds->count()} foundation event(s).");

        return self::SUCCESS;
    }
}
