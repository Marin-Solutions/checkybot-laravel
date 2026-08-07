<?php

namespace MarinSolutions\CheckybotLaravel;

use MarinSolutions\CheckybotLaravel\Commands\CheckybotCommand;
use MarinSolutions\CheckybotLaravel\Domain\Agent\AgentServiceProvider;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\AlertingServiceProvider;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\MonitoringFoundationServiceProvider;
use MarinSolutions\CheckybotLaravel\Domain\Push\PushServiceProvider;
use MarinSolutions\CheckybotLaravel\Http\CheckybotClient;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class CheckybotLaravelServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('checkybot-laravel')
            ->hasConfigFile()
            ->hasCommand(CheckybotCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->register(MonitoringFoundationServiceProvider::class);
        $this->app->register(AlertingServiceProvider::class);
        $this->app->register(AgentServiceProvider::class);
        $this->app->register(PushServiceProvider::class);

        // Register CheckRegistry as singleton
        $this->app->singleton(CheckRegistry::class, function () {
            return new CheckRegistry;
        });

        $this->app->singleton(CheckybotClient::class, function ($app) {
            return new CheckybotClient(
                baseUrl: config('checkybot-laravel.base_url'),
                apiKey: config('checkybot-laravel.api_key'),
                projectId: config('checkybot-laravel.project_id'),
                timeout: config('checkybot-laravel.timeout'),
                retryTimes: config('checkybot-laravel.retry_times'),
                retryDelay: config('checkybot-laravel.retry_delay')
            );
        });

        $this->app->singleton(ConfigValidator::class, function ($app) {
            return new ConfigValidator;
        });
    }

    public function packageBooted(): void
    {
        $this->publishCheckybotRoutes();
        $this->loadCheckybotRoutes();
    }

    /**
     * Register the checkybot routes stub for publishing.
     */
    protected function publishCheckybotRoutes(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../stubs/checkybot.php.stub' => base_path('routes/checkybot.php'),
            ], 'checkybot-routes');
        }
    }

    /**
     * Load the checkybot routes file if it exists.
     */
    protected function loadCheckybotRoutes(): void
    {
        require __DIR__.'/../routes/agent.php';

        $routesPath = base_path('routes/checkybot.php');

        if (file_exists($routesPath)) {
            require $routesPath;
        }
    }
}
