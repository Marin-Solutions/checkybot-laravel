<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Actions\IngestMonitorResult;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Console\DispatchDueIncidentGroups;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Console\DispatchDuePullRechecks;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Console\PingExternalWatchdog;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\HeartbeatClient;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\MonitorResultIngestionInterface;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\PullRecheckProducer;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Http\AlertingReceiptController;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Http\AlertingResultController;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Notifications\AlertingEventDispatcher;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Support\DeterministicPullRecheckProducer;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Support\LaravelHeartbeatClient;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\MaintenanceServiceProvider;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery\FoundationEventDispatcher;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Http\RequireLoopback;

final class AlertingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/watchdog.php', 'checkybot.alerting.watchdog');
        $this->app->register(MaintenanceServiceProvider::class);
        $this->app->bind(HeartbeatClient::class, LaravelHeartbeatClient::class);
        $this->app->bind(MonitorResultIngestionInterface::class, IngestMonitorResult::class);
        $this->app->singleton(DeterministicPullRecheckProducer::class);
        $this->app->bind(PullRecheckProducer::class, static fn ($app): PullRecheckProducer => $app->make(DeterministicPullRecheckProducer::class));
        $this->app->singleton(FoundationEventDispatcher::class, AlertingEventDispatcher::class);

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('checkybot:alerting-retries')
                ->name('checkybot:alerting-retries')
                ->everySecond()
                ->withoutOverlapping();
            $schedule->command('checkybot:alerting-groups')
                ->name('checkybot:alerting-groups')
                ->everySecond()
                ->withoutOverlapping();
            $schedule->command('checkybot:watchdog')
                ->name('checkybot:watchdog')
                ->everyMinute()
                ->withoutOverlapping(1);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DispatchDueIncidentGroups::class,
                DispatchDuePullRechecks::class,
                PingExternalWatchdog::class,
            ]);
        }

        if ($this->app->environment(['testing', 'harness'])) {
            Route::middleware(['api', RequireLoopback::class])->group(static function (): void {
                Route::post('/__harness/alerting/results', AlertingResultController::class);
                Route::get('/__harness/alerting/receipts/{operationId}', AlertingReceiptController::class)
                    ->whereUuid('operationId');
            });
        }
    }
}
