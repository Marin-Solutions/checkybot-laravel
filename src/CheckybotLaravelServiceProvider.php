<?php

namespace MarinSolutions\CheckybotLaravel;

use MarinSolutions\CheckybotLaravel\Commands\CheckybotCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class CheckybotLaravelServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('checkybot-laravel')
            ->hasConfigFile()
            ->hasViews()
            ->hasCommand(CheckybotCommand::class);
    }
}
