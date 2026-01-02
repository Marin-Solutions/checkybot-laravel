<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

it('publishes config file', function () {
    // Clean up any existing published config
    $configPath = config_path('checkybot-laravel.php');
    if (File::exists($configPath)) {
        File::delete($configPath);
    }

    // Publish the config
    Artisan::call('vendor:publish', [
        '--tag' => 'checkybot-laravel-config',
    ]);

    expect(File::exists($configPath))->toBeTrue();

    // Verify it contains expected keys
    $config = include $configPath;
    expect($config)->toHaveKeys(['api_key', 'project_id', 'base_url', 'checks']);

    // Clean up
    File::delete($configPath);
});

it('registers the sync command', function () {
    $commands = Artisan::all();

    expect($commands)->toHaveKey('checkybot:sync');
});

it('loads config with correct default values', function () {
    expect(config('checkybot-laravel.base_url'))->toBe('https://checkybot.com')
        ->and(config('checkybot-laravel.timeout'))->toBe(30)
        ->and(config('checkybot-laravel.retry_times'))->toBe(3)
        ->and(config('checkybot-laravel.retry_delay'))->toBe(1000)
        ->and(config('checkybot-laravel.checks.uptime'))->toBeArray()
        ->and(config('checkybot-laravel.checks.ssl'))->toBeArray()
        ->and(config('checkybot-laravel.checks.api'))->toBeArray();
});
