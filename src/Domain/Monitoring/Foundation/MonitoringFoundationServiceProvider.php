<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Console\RelayFoundationOutbox;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\ContractValidator;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery\DelayedDeliveryFake;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery\DeterministicFakeEventDispatcher;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery\FoundationEventDispatcher;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery\FoundationEventProcessor;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery\RetryableFailureFake;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery\TerminalFailureFake;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Jobs\DeliverFoundationEvent;
use MarinSolutions\CheckybotLaravel\Domain\Security\Foundation\RecursiveRedactor;

final class MonitoringFoundationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 4).'/config/checkybot.php', 'checkybot');
        $this->app->singleton(ContractValidator::class);
        $this->app->singleton(RecursiveRedactor::class, static fn (): RecursiveRedactor => RecursiveRedactor::fromConfiguration());
        $this->app->singleton(FoundationEventDispatcher::class, DeterministicFakeEventDispatcher::class);
        $this->app->singleton(DeterministicFakeEventDispatcher::class);
        $this->app->bind(RetryableFailureFake::class);
        $this->app->bind(TerminalFailureFake::class);
        $this->app->bind(DelayedDeliveryFake::class);
        $this->app->bind(FoundationEventProcessor::class);
        $this->app->bind(DeliverFoundationEvent::class);

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('checkybot:foundation-relay')
                ->name('checkybot:foundation-relay')
                ->everyMinute()
                ->withoutOverlapping();
        });
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
