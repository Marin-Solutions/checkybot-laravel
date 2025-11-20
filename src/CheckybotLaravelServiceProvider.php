<?php

namespace MarinSolutions\CheckybotLaravel;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use MarinSolutions\CheckybotLaravel\Commands\CheckybotCommand;

class CheckybotServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('checkybot-laravel-temp')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_checkybot_laravel_temp_table')
            ->hasCommand(CheckybotCommand::class);
    }
}
