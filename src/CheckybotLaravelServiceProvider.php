<?php

namespace MarinSolutions\CheckybotLaravel;

use Illuminate\Support\Facades\Route;
use MarinSolutions\CheckybotLaravel\Commands\CheckybotCommand;
use MarinSolutions\CheckybotLaravel\Domain\Agent\AgentServiceProvider;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\AlertingServiceProvider;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\ExpandedChecksServiceProvider;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Http\RequireLoopback;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\MonitoringFoundationServiceProvider;
use MarinSolutions\CheckybotLaravel\Domain\Push\PushServiceProvider;
use MarinSolutions\CheckybotLaravel\Http\CheckybotClient;
use MarinSolutions\CheckybotLaravel\Http\Controllers\Harness\CheckSyncCaptureController;
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
        $this->app->register(ExpandedChecksServiceProvider::class);
        $this->app->register(PushServiceProvider::class);

        $this->app->singleton(CheckSyncPayloadSerializer::class, fn () => new CheckSyncPayloadSerializer);

        // Registry and config paths share the same canonical serializer boundary.
        $this->app->singleton(CheckRegistry::class, function ($app) {
            return new CheckRegistry($app->make(CheckSyncPayloadSerializer::class));
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
            return new ConfigValidator($app->make(CheckSyncPayloadSerializer::class));
        });
    }

    public function packageBooted(): void
    {
        $this->publishCheckybotRoutes();
        $this->loadCheckybotRoutes();
        $this->registerHarnessSyncCaptureRoute();
    }

    /**
     * Expose the SDK transport receiver only to the canonical loopback runtime.
     */
    private function registerHarnessSyncCaptureRoute(): void
    {
        if (! $this->app->environment(['testing', 'harness'])) {
            return;
        }

        Route::middleware(['api', RequireLoopback::class])
            ->post('/api/v1/projects/{projectId}/checks/sync', CheckSyncCaptureController::class)
            ->where('projectId', '[^/]+');
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
        require __DIR__.'/../routes/web-dashboard.php';

        $routesPath = base_path('routes/checkybot.php');

        if (file_exists($routesPath)) {
            require $routesPath;
        }
    }
}
