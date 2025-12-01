<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel;

use Illuminate\Support\ServiceProvider;
use MarinSolutions\CheckybotLaravel\Console\SyncCommand;
use MarinSolutions\CheckybotLaravel\Http\CheckybotClient;
use MarinSolutions\CheckybotLaravel\Support\Constants;

/**
 * Service provider for Checkybot Laravel package
 */
class CheckybotLaravelServiceProvider extends ServiceProvider
{
    /**
     * Register package services
     */
    public function register(): void
    {
        $this->mergeConfig();
        $this->registerClient();
    }

    /**
     * Merge package configuration
     */
    protected function mergeConfig(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/checkybot-laravel.php',
            Constants::CONFIG_KEY
        );
    }

    /**
     * Register CheckybotClient as singleton
     */
    protected function registerClient(): void
    {
        $this->app->singleton(CheckybotClient::class, function ($app) {
            $config = config(Constants::CONFIG_KEY);

            return new CheckybotClient(
                $config['base_url'] ?? Constants::DEFAULT_BASE_URL,
                $config['api_key'] ?? '',
                $config['project_id'] ?? '',
                $config['timeout'] ?? Constants::DEFAULT_TIMEOUT,
                $config['retry_times'] ?? Constants::DEFAULT_RETRY_TIMES,
                $config['retry_delay'] ?? Constants::DEFAULT_RETRY_DELAY
            );
        });
    }

    /**
     * Bootstrap package services
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishConfig();
            $this->registerCommands();
        }
    }

    /**
     * Publish configuration file
     */
    protected function publishConfig(): void
    {
        $this->publishes([
            __DIR__.'/../config/checkybot-laravel.php' => config_path('checkybot-laravel.php'),
        ], Constants::CONFIG_PUBLISH_TAG);
    }

    /**
     * Register Artisan commands
     */
    protected function registerCommands(): void
    {
        $this->commands([
            SyncCommand::class,
        ]);
    }
}
