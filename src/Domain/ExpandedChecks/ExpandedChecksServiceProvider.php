<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks;

use GuzzleHttp\Client;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\PullRecheckProducer;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Console\EvaluateDueDomainBudgets;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Console\EvaluateDueResponseBudgets;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Console\RefreshDueDomains;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Contracts\DomainExpiryLookup;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Contracts\StoredCheckSpeedReader;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Support\DomainExpiryPullRecheckProducer;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Support\EloquentStoredCheckSpeedReader;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Support\RdapDomainExpiryLookup;

final class ExpandedChecksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DomainExpiryLookup::class, static fn (): DomainExpiryLookup => new RdapDomainExpiryLookup(
            new Client,
            (float) config('checkybot.expanded_checks.domain_lookup_timeout_seconds', 5),
        ));
        $this->app->bind(StoredCheckSpeedReader::class, EloquentStoredCheckSpeedReader::class);
        $this->app->bind(PullRecheckProducer::class, DomainExpiryPullRecheckProducer::class);

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('checkybot:expanded-refresh-domains')
                ->name('checkybot:expanded-refresh-domains')->daily()->withoutOverlapping(30)->onOneServer();
            $schedule->command('checkybot:expanded-evaluate-domains')
                ->name('checkybot:expanded-evaluate-domains')->everyMinute()->withoutOverlapping(2)->onOneServer();
            $schedule->command('checkybot:expanded-evaluate-response-budgets')
                ->name('checkybot:expanded-evaluate-response-budgets')->everyMinute()->withoutOverlapping(2)->onOneServer();
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                RefreshDueDomains::class,
                EvaluateDueDomainBudgets::class,
                EvaluateDueResponseBudgets::class,
            ]);
        }
    }
}
