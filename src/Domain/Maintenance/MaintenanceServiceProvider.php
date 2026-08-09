<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Maintenance;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Console\DispatchExpiredMaintenanceModes;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Console\MaintenanceCommand;

final class MaintenanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('checkybot:maintenance-expire')
                ->name('checkybot:maintenance-expire')
                ->everyMinute()
                ->withoutOverlapping();
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(dirname(__DIR__, 3).'/routes/maintenance.php');

        if ($this->app->runningInConsole()) {
            $this->commands([DispatchExpiredMaintenanceModes::class, MaintenanceCommand::class]);
        }
    }
}
