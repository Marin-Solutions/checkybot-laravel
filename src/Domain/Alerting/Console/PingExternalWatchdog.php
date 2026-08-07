<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Console;

use Illuminate\Console\Command;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Actions\PingWatchdogHeartbeat;

final class PingExternalWatchdog extends Command
{
    protected $signature = 'checkybot:watchdog';

    protected $description = 'Ping the configured external Checkybot heartbeat watchdog';

    public function handle(PingWatchdogHeartbeat $ping): int
    {
        $result = $ping->execute();

        if ($result->status === 'disabled') {
            $this->components->info('Checkybot watchdog is disabled.');
        } elseif ($result->status === 'success') {
            $this->components->info("Checkybot watchdog heartbeat succeeded ({$result->httpStatus}).");
        } else {
            $this->components->warn("Checkybot watchdog heartbeat failed ({$result->reason}).");
        }

        // A heartbeat failure is an observed condition, not a scheduler failure. Keeping
        // this command successful lets the scheduler continue with unrelated due work.
        return self::SUCCESS;
    }
}
