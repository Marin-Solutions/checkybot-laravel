<?php

namespace MarinSolutions\CheckybotLaravel;

use MarinSolutions\CheckybotLaravel\Commands\CheckybotCommand;
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
            return new ConfigValidator();
        });
    }
}
