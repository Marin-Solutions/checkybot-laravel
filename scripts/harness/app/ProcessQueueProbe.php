<?php

declare(strict_types=1);

namespace Checkybot\Harness;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class ProcessQueueProbe implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly string $probeId) {}

    public function handle(): void
    {
        DB::table('harness_queue_probes')
            ->where('probe_id', $this->probeId)
            ->update([
                'status' => 'processed',
                'processed_at' => now()->toISOString(),
            ]);
    }
}
