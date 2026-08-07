<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation;

use Illuminate\Support\ServiceProvider;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Console\RelayFoundationOutbox;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\ContractValidator;

final class MonitoringFoundationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 4).'/config/checkybot.php', 'checkybot');
        $this->app->singleton(ContractValidator::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 4).'/database/migrations');
        $this->loadRoutesFrom(dirname(__DIR__, 4).'/routes/status-summary.php');

        if ($this->app->runningInConsole()) {
            $this->commands([RelayFoundationOutbox::class]);
        }
    }
}
