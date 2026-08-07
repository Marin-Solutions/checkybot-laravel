<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Console\EvaluateDueAgentMonitors;

final class AgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('checkybot:agent-evaluate-due')
                ->name('checkybot:agent-evaluate-due')
                ->everyMinute()
                ->withoutOverlapping(2)
                ->onOneServer();
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([EvaluateDueAgentMonitors::class]);
        }
    }
}
